<?php

namespace Omnistate\AnnuaireSante;

use Omnistate\Exception\UnavailableException;
use Omnistate\Identifier\Finess;
use Omnistate\Identifier\Rpps;
use Omnistate\Model\Address;
use Omnistate\Model\Facility;
use Omnistate\Model\Professional;
use Omnistate\Model\Qualification;
use Omnistate\Model\Workplace;
use Omnistate\Registry\FacilityRegistryInterface;
use Omnistate\Registry\ProfessionalRegistryInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Annuaire Santé of the Agence du numérique en santé, through its FHIR
 * API (version 2): health professionals by their RPPS number - profession,
 * specialties, diplomas, where they practise and how, their MSSanté
 * addresses - and health facilities by their FINESS number. Free, with a
 * key from the ANS's Gravitee portal (header ESANTE-API-KEY), 17 calls a
 * second. Production data only: the ANS has no sandbox with fictional people.
 *
 * Without a key every call throws UnavailableException: not knowing is not
 * "no such professional".
 */
final class AnnuaireSante implements ProfessionalRegistryInterface, FacilityRegistryInterface
{
    public const URL = 'https://gateway.api.esante.gouv.fr/fhir/v2';

    private const PROFESSION = 'TRE_G15-ProfessionSante';
    private const CATEGORY = 'TRE_R09-CategorieProfessionnelle';
    private const DIPLOMAS = ['TRE_R48-DiplomeEtatFrancais', 'TRE_R14-TypeDiplome', 'TRE_R49-DiplomeEtudesSpecialisees', 'TRE_R47-DiplomeUniversitaire'];
    private const MODE = 'TRE_R23-ModeExercice';
    private const ROLE = 'TRE_R21-Fonction';
    private const MSSANTE = 'MSSANTE';

    /** TRE_G15, the professions the register knows by code (the ones a practice meets). */
    public const PROFESSIONS = [
        '10' => 'Médecin',
        '21' => 'Pharmacien',
        '26' => 'Audioprothésiste',
        '28' => 'Opticien-lunetier',
        '40' => 'Chirurgien-dentiste',
        '50' => 'Sage-femme',
        '60' => 'Infirmier',
        '69' => 'Infirmier psychiatrique',
        '70' => 'Masseur-kinésithérapeute',
        '80' => 'Pédicure-podologue',
        '81' => 'Orthoprothésiste',
        '82' => 'Podo-orthésiste',
        '83' => 'Orthopédiste-orthésiste',
        '84' => 'Oculariste',
        '85' => 'Épithésiste',
        '86' => 'Technicien de laboratoire médical',
        '91' => 'Orthophoniste',
        '92' => 'Orthoptiste',
        '93' => 'Psychologue',
        '94' => 'Ergothérapeute',
        '95' => 'Diététicien',
        '96' => 'Psychomotricien',
        '98' => 'Manipulateur ERM',
    ];

    /** TRE_R23: how one practises. */
    private const MODES = ['L' => 'liberal', 'S' => 'salaried', 'B' => 'volunteer'];

    public function __construct(
        private readonly HttpClientInterface $http,
        #[\SensitiveParameter] private readonly ?string $apiKey = null,
        private readonly float $timeout = 10,
        private readonly string $url = self::URL,
    ) {
    }

    public function name(): string
    {
        return 'annuaire-sante';
    }

    public function supports(string $identifier): bool
    {
        return null !== Rpps::normalize($identifier);
    }

    public function supportsFacility(string $identifier): bool
    {
        return null !== Finess::normalize($identifier);
    }

    public function professional(string $identifier): ?Professional
    {
        $rpps = Rpps::normalize($identifier) ?? throw new \InvalidArgumentException(sprintf('"%s" is not an RPPS number.', $identifier));
        $bundle = $this->get('Practitioner', [
            'identifier' => $rpps,
            '_revinclude' => 'PractitionerRole:practitioner',
            '_include:iterate' => 'PractitionerRole:organization',
        ]);

        foreach ($this->professionals($bundle) as $professional) {
            if ($professional->identifier === $rpps) {
                return $professional;
            }
        }

        return null;
    }

    public function search(string $name, ?string $postcode = null, int $limit = 10): array
    {
        $name = trim($name);
        if ('' === $name) {
            return [];
        }
        $bundle = $this->get('Practitioner', [
            'name' => $name,
            'active' => 'true',
            '_count' => null === $postcode ? $limit : 50,
            '_revinclude' => 'PractitionerRole:practitioner',
            '_include:iterate' => 'PractitionerRole:organization',
        ]);

        $found = [];
        foreach ($this->professionals($bundle) as $professional) {
            if (null !== $postcode && !$this->practisesIn($professional, $postcode)) {
                continue;
            }
            $found[] = $professional;
            if (\count($found) >= $limit) {
                break;
            }
        }

        return $found;
    }

    public function facility(string $identifier): ?Facility
    {
        $finess = Finess::normalize($identifier) ?? throw new \InvalidArgumentException(sprintf('"%s" is not a FINESS number.', $identifier));
        $bundle = $this->get('Organization', ['identifier' => $finess]);

        foreach ($this->resources($bundle, 'Organization') as $organization) {
            $facility = $this->facilityOf($organization);
            if ($facility->identifier === $finess) {
                return $facility;
            }
        }

        return null;
    }

    /**
     * The professionals of a search answer, each with the roles and the
     * organizations it included.
     *
     * @return list<Professional>
     */
    public function professionals(array $bundle): array
    {
        $organizations = [];
        foreach ($this->resources($bundle, 'Organization') as $organization) {
            $organizations['Organization/'.($organization['id'] ?? '')] = $organization;
        }
        $roles = [];
        foreach ($this->resources($bundle, 'PractitionerRole') as $role) {
            $roles[$role['practitioner']['reference'] ?? ''][] = $role;
        }

        $professionals = [];
        foreach ($this->resources($bundle, 'Practitioner') as $practitioner) {
            $professional = $this->professionalOf($practitioner, $roles['Practitioner/'.($practitioner['id'] ?? '')] ?? [], $organizations);
            if (null !== $professional) {
                $professionals[] = $professional;
            }
        }

        return $professionals;
    }

    /**
     * @param list<array> $roles
     * @param array<string, array> $organizations
     */
    private function professionalOf(array $practitioner, array $roles, array $organizations): ?Professional
    {
        $rpps = null;
        foreach ($practitioner['identifier'] ?? [] as $identifier) {
            $type = $identifier['type']['coding'][0]['code'] ?? null;
            if ('RPPS' === $type || 'https://rpps.esante.gouv.fr' === ($identifier['system'] ?? null)) {
                $rpps = Rpps::normalize($identifier['value'] ?? null);
                break;
            }
            if ('IDNPS' === $type) {
                $rpps ??= Rpps::normalize($identifier['value'] ?? null);
            }
        }
        if (null === $rpps) {
            return null;
        }

        $name = $this->usualName($practitioner['name'] ?? []);
        $profession = null;
        $specialties = [];
        $diplomas = [];
        foreach ($practitioner['qualification'] ?? [] as $qualification) {
            foreach ($qualification['code']['coding'] ?? [] as $coding) {
                $system = $this->system($coding['system'] ?? '');
                if (self::PROFESSION === $system) {
                    $profession ??= $this->profession($coding);
                } elseif (self::CATEGORY === $system) {
                    continue;
                } elseif (\in_array($system, self::DIPLOMAS, true)) {
                    // The diploma's own code, not its type's ("DE05", not "DE").
                    if ('TRE_R14-TypeDiplome' !== $system) {
                        $diplomas[] = $this->qualification($coding);
                    }
                } elseif (isset($coding['code'])) {
                    $specialties[] = $this->qualification($coding);
                }
            }
        }

        $workplaces = [];
        foreach ($roles as $role) {
            foreach ($role['code'] ?? [] as $code) {
                foreach ($code['coding'] ?? [] as $coding) {
                    if (null === $profession && self::PROFESSION === $this->system($coding['system'] ?? '')) {
                        $profession = $this->profession($coding);
                    }
                }
            }
            foreach ($role['specialty'] ?? [] as $specialty) {
                foreach ($specialty['coding'] ?? [] as $coding) {
                    if (isset($coding['code']) && !$this->has($specialties, $coding['code'])) {
                        $specialties[] = $this->qualification($coding);
                    }
                }
            }
            $workplaces[] = $this->workplace($role, $organizations[$role['organization']['reference'] ?? ''] ?? null);
        }
        usort($workplaces, static fn (Workplace $a, Workplace $b) => $b->active <=> $a->active);

        [$phones, $emails, $secure] = $this->telecom($practitioner['telecom'] ?? []);
        foreach ($roles as $role) {
            [, , $roleSecure] = $this->telecom($role['telecom'] ?? []);
            $secure = array_values(array_unique([...$secure, ...$roleSecure]));
        }

        $languages = [];
        foreach ($practitioner['communication'] ?? [] as $communication) {
            if (isset($communication['coding'][0]['code'])) {
                $languages[] = strtolower($communication['coding'][0]['code']);
            }
        }

        return new Professional(
            identifier: $rpps,
            familyName: $name['family'],
            source: $this->name(),
            givenName: $name['given'],
            prefix: $name['prefix'],
            profession: $profession,
            specialties: $specialties,
            diplomas: $diplomas,
            workplaces: $workplaces,
            phones: $phones,
            emails: $emails,
            secureEmails: $secure,
            languages: $languages,
            active: (bool) ($practitioner['active'] ?? true),
            updatedAt: $this->date($practitioner['meta']['lastUpdated'] ?? null),
            raw: $practitioner,
        );
    }

    private function workplace(array $role, ?array $organization): Workplace
    {
        $mode = null;
        $function = null;
        foreach ($role['code'] ?? [] as $code) {
            foreach ($code['coding'] ?? [] as $coding) {
                $system = $this->system($coding['system'] ?? '');
                if (self::MODE === $system) {
                    $mode = self::MODES[$coding['code'] ?? ''] ?? ($coding['code'] ?? null);
                } elseif (self::ROLE === $system) {
                    $function = $coding['display'] ?? $coding['code'] ?? null;
                }
            }
        }
        [$phones, , $secure] = $this->telecom([...($organization['telecom'] ?? []), ...($role['telecom'] ?? [])]);

        return new Workplace(
            name: $organization['name'] ?? null,
            address: isset($organization['address'][0]) ? $this->address($organization['address'][0]) : null,
            facility: null !== $organization ? $this->finess($organization) ?? ($organization['id'] ?? null) : null,
            mode: $mode,
            role: $function,
            phones: $phones,
            secureEmails: $secure,
            active: (bool) ($role['active'] ?? true) && null === ($role['period']['end'] ?? null),
        );
    }

    private function facilityOf(array $organization): Facility
    {
        $kind = null;
        $identifiers = [];
        foreach ($organization['identifier'] ?? [] as $identifier) {
            $type = $identifier['type']['coding'][0]['code'] ?? null;
            if ('FINEG' === $type) {
                $kind = 'site';
            } elseif ('FINEJ' === $type) {
                $kind = 'legal';
            }
            if (isset($identifier['value'])) {
                $identifiers[] = (string) $identifier['value'];
            }
        }
        $types = [];
        foreach ($organization['type'] ?? [] as $type) {
            foreach ($type['coding'] ?? [] as $coding) {
                if (isset($coding['code']) && 'https://hl7.fr/ig/fhir/core/CodeSystem/fr-core-cs-v2-3307' !== ($coding['system'] ?? null)) {
                    $types[] = $this->qualification($coding);
                }
            }
        }
        [$phones, $emails, $secure] = $this->telecom($organization['telecom'] ?? []);

        return new Facility(
            identifier: $this->finess($organization) ?? (string) ($organization['id'] ?? ''),
            name: (string) ($organization['name'] ?? ''),
            source: $this->name(),
            kind: $kind,
            types: $types,
            address: isset($organization['address'][0]) ? $this->address($organization['address'][0]) : null,
            phones: $phones,
            emails: $emails,
            secureEmails: $secure,
            identifiers: $identifiers,
            active: (bool) ($organization['active'] ?? true),
            updatedAt: $this->date($organization['meta']['lastUpdated'] ?? null),
            raw: $organization,
        );
    }

    private function finess(array $organization): ?string
    {
        foreach ($organization['identifier'] ?? [] as $identifier) {
            if ('https://finess.esante.gouv.fr' === ($identifier['system'] ?? null) || \in_array($identifier['type']['coding'][0]['code'] ?? null, ['FINEG', 'FINEJ'], true)) {
                return Finess::normalize($identifier['value'] ?? null);
            }
        }

        return null;
    }

    /** @return array{family: string, given: ?string, prefix: ?string} */
    private function usualName(array $names): array
    {
        $chosen = $names[0] ?? [];
        foreach ($names as $name) {
            if ('usual' === ($name['use'] ?? null)) {
                $chosen = $name;
                break;
            }
        }

        return [
            'family' => (string) ($chosen['family'] ?? ''),
            'given' => isset($chosen['given']) ? implode(' ', $chosen['given']) : null,
            'prefix' => $chosen['prefix'][0] ?? null,
        ];
    }

    /** @return array{list<string>, list<string>, list<string>} phones, e-mails, secure (MSSanté) e-mails */
    private function telecom(array $telecom): array
    {
        $phones = $emails = $secure = [];
        foreach ($telecom as $point) {
            $value = trim((string) ($point['value'] ?? ''));
            if ('' === $value) {
                continue;
            }
            if ('phone' === ($point['system'] ?? null)) {
                $phones[] = $value;
            } elseif ('email' === ($point['system'] ?? null)) {
                $isSecure = str_ends_with(strtolower($value), '.mssante.fr');
                foreach ($point['extension'] ?? [] as $extension) {
                    if (self::MSSANTE === ($extension['valueCoding']['code'] ?? null)) {
                        $isSecure = true;
                    }
                }
                if ($isSecure) {
                    $secure[] = $value;
                } else {
                    $emails[] = $value;
                }
            }
        }

        return [array_values(array_unique($phones)), array_values(array_unique($emails)), array_values(array_unique($secure))];
    }

    private function address(array $address): Address
    {
        $street = null;
        if (!empty($address['line'][0]) && empty($address['_line'][0]['extension'])) {
            $street = (string) $address['line'][0];
        } else {
            $parts = [];
            foreach ($address['_line'][0]['extension'] ?? [] as $extension) {
                $key = substr((string) ($extension['url'] ?? ''), strrpos((string) ($extension['url'] ?? ''), '-') + 1);
                if (\in_array($key, ['houseNumber', 'buildingNumberSuffix', 'streetNameType', 'streetNameBase'], true)) {
                    $parts[] = $extension['valueString'] ?? null;
                }
            }
            $street = trim(implode(' ', array_filter($parts))) ?: (isset($address['line'][0]) ? (string) $address['line'][0] : null);
        }
        $postalCode = $address['postalCode'] ?? null;
        $city = isset($address['city']) ? trim((string) preg_replace('/^\d{5}\s+/', '', (string) $address['city'])) : null;

        return new Address(
            street: $street ?: null,
            postalCode: $postalCode,
            city: $city ?: null,
            // The INSEE country code: 99100 is France; none written means France too.
            country: match ($address['country'] ?? null) { null, '99100', 'FR', 'FRA' => 'FR', default => null },
        );
    }

    private function profession(array $coding): Qualification
    {
        $code = (string) ($coding['code'] ?? '');

        return new Qualification($code, self::PROFESSION, $coding['display'] ?? self::PROFESSIONS[$code] ?? null);
    }

    private function qualification(array $coding): Qualification
    {
        return new Qualification((string) $coding['code'], $this->system($coding['system'] ?? ''), $coding['display'] ?? null);
    }

    /** ".../NOS/TRE_G15-ProfessionSante/FHIR/TRE-G15-ProfessionSante" -> "TRE_G15-ProfessionSante" */
    private function system(string $system): string
    {
        return preg_match('~/NOS/([^/]+)/~', $system, $match) ? $match[1] : $system;
    }

    /** @param list<Qualification> $qualifications */
    private function has(array $qualifications, string $code): bool
    {
        foreach ($qualifications as $qualification) {
            if ($qualification->code === $code) {
                return true;
            }
        }

        return false;
    }

    private function practisesIn(Professional $professional, string $postcode): bool
    {
        $postcode = trim($postcode);
        foreach ($professional->workplaces as $workplace) {
            if ($workplace->address?->postalCode && str_starts_with($workplace->address->postalCode, $postcode)) {
                return true;
            }
        }

        return false;
    }

    /** @return iterable<array> the resources of that type in a search Bundle */
    private function resources(array $bundle, string $type): iterable
    {
        if (($bundle['resourceType'] ?? null) === $type) {
            yield $bundle;

            return;
        }
        foreach ($bundle['entry'] ?? [] as $entry) {
            if (($entry['resource']['resourceType'] ?? null) === $type) {
                yield $entry['resource'];
            }
        }
    }

    private function date(?string $date): ?\DateTimeImmutable
    {
        try {
            return null === $date ? null : new \DateTimeImmutable($date);
        } catch (\Exception) {
            return null;
        }
    }

    private function get(string $resource, array $query): array
    {
        if (null === $this->apiKey || '' === trim($this->apiKey)) {
            throw new UnavailableException('Annuaire Santé: no API key (ESANTE-API-KEY, from the ANS Gravitee portal).');
        }

        try {
            $response = $this->http->request('GET', rtrim($this->url, '/').'/'.$resource, [
                'query' => $query,
                'headers' => ['ESANTE-API-KEY' => $this->apiKey, 'Accept' => 'application/fhir+json'],
                'timeout' => $this->timeout,
            ]);
            $status = $response->getStatusCode();
            if (429 === $status) {
                $retry = $response->getHeaders(false)['retry-after'][0] ?? null;

                throw new UnavailableException('Annuaire Santé: too many calls (17 a second).', null !== $retry ? (int) $retry : 1);
            }
            if (401 === $status || 403 === $status) {
                throw new UnavailableException(sprintf('Annuaire Santé: the key was refused (%d).', $status));
            }
            if ($status >= 500) {
                throw new UnavailableException(sprintf('Annuaire Santé: the server answered %d.', $status));
            }
            if (404 === $status) {
                return [];
            }

            return $response->toArray();
        } catch (ExceptionInterface $e) {
            throw new UnavailableException('Annuaire Santé did not answer: '.$e->getMessage(), null, $e);
        }
    }
}
