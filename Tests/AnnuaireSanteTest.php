<?php

namespace Omnistate\AnnuaireSante\Tests;

use Omnistate\AnnuaireSante\AnnuaireSante;
use Omnistate\Exception\UnavailableException;
use Omnistate\Omnistate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * On the examples of the ANS's implementation guide (interop.esante.gouv.fr/ig/fhir/annuaire),
 * fictional people, put in search Bundles as the API v2 answers them; their e-mail
 * addresses replaced by example ones.
 */
final class AnnuaireSanteTest extends TestCase
{
    public function testAPhysicianByRpps(): void
    {
        $requests = [];
        $professional = $this->registry('practitioner-physician.json', $requests)->professional('100 0346 1033');

        self::assertSame('10003461033', $professional->identifier);
        self::assertSame('Arthur Saucier', $professional->name());
        self::assertSame('M', $professional->prefix);
        self::assertSame('10', $professional->profession->code);
        self::assertSame('Médecin', (string) $professional->profession);
        self::assertSame('DE05', $professional->diplomas[0]->code);
        self::assertSame(['fr'], $professional->languages);
        self::assertSame(['0603590791'], $professional->phones);
        self::assertSame('CABINET SAINT ANTOINE', $professional->workplaces[0]->name);
        self::assertSame('liberal', $professional->workplaces[0]->mode);
        self::assertTrue($professional->workplaces[0]->active);
        self::assertSame('annuaire-sante', $professional->source);

        [$method, $url, $options] = $requests[0];
        self::assertSame('GET', $method);
        self::assertStringStartsWith('https://gateway.api.esante.gouv.fr/fhir/v2/Practitioner?', $url);
        self::assertStringContainsString('identifier=10003461033', $url);
        self::assertStringContainsString('_revinclude=PractitionerRole:practitioner', $url);
        self::assertContains('ESANTE-API-KEY: test-key', $options['headers']);
    }

    public function testTheIdnpsFormIsTheSameProfessional(): void
    {
        self::assertSame('10003461033', $this->registry('practitioner-physician.json')->professional('810003461033')->identifier);
    }

    public function testAPharmacistsMssanteAddressIsTold(): void
    {
        $professional = $this->registry('practitioner-pharmacist.json')->professional('10102727017');

        self::assertSame('David CHATELIER', $professional->name());
        self::assertSame(['david.chatelier@exemple.mssante.fr'], $professional->secureEmails, 'his own MSSanté box');
        self::assertSame(['pharmacie@exemple.mssante.fr'], $professional->workplaces[0]->secureEmails, 'his workplace\'s');
        self::assertSame(['david.chatelier@example.org'], $professional->emails);
        self::assertSame('PHARMACIE NOLOT', $professional->workplaces[0]->name);
        self::assertSame('580008803', $professional->workplaces[0]->facility, 'the FINESS of the legal entity');
    }

    public function testSomeoneElsesAnswerIsNotTheirs(): void
    {
        self::assertNull($this->registry('practitioner-physician.json')->professional('10102727017'));
        self::assertNull($this->registry('empty.json')->professional('10003461033'));
    }

    public function testASearchByNameNarrowedToAPostcode(): void
    {
        $registry = $this->registry('search.json');

        self::assertCount(2, $registry->search('a'));
        $inClamart = $registry->search('a', '92');
        self::assertCount(1, $inClamart);
        self::assertSame('Arthur Saucier', $inClamart[0]->name());
        self::assertSame('CLAMART', $inClamart[0]->workplaces[0]->address->city);
        self::assertSame('92140', $inClamart[0]->workplaces[0]->address->postalCode);
        self::assertSame([], $registry->search('  '));
    }

    public function testAFacilityByFiness(): void
    {
        $facility = $this->registry('organization.json')->facility('754 567 860');

        self::assertSame('754567860', $facility->identifier);
        self::assertSame('CH EURE-SEINE', $facility->name);
        self::assertSame('site', $facility->kind, 'a FINESS géographique');
        self::assertSame('96 R DIDOT', $facility->address->street);
        self::assertSame('PARIS', $facility->address->city);
        self::assertSame(['0450636363'], $facility->phones);
        self::assertSame(['SA01', '86.10Z'], array_map(static fn ($type) => $type->code, $facility->types));
    }

    public function testWithoutAKeyNothingIsAsked(): void
    {
        $asked = false;
        $registry = new AnnuaireSante(new MockHttpClient(function () use (&$asked) {
            $asked = true;

            return new MockResponse('{}');
        }));

        $this->expectException(UnavailableException::class);
        try {
            $registry->professional('10003461033');
        } finally {
            self::assertFalse($asked);
        }
    }

    public function testTooManyCallsSaysWhenToComeBack(): void
    {
        $registry = new AnnuaireSante(new MockHttpClient(new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 2']])), 'test-key');

        try {
            $registry->professional('10003461033');
            self::fail('no exception');
        } catch (UnavailableException $e) {
            self::assertSame(2, $e->retryAfter);
        }
    }

    public function testThroughOmnistate(): void
    {
        $omnistate = new Omnistate(professionals: [$this->registry('practitioner-physician.json')]);

        self::assertSame('Médecin', (string) $omnistate->professional('10003461033')->profession);
        self::assertTrue($this->registry('organization.json')->supportsFacility('2A0001234'), 'Corsica');
    }

    private function registry(string $fixture, ?array &$requests = null): AnnuaireSante
    {
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($fixture, &$requests) {
            $requests[] = [$method, $url, $options];

            return new MockResponse(file_get_contents(__DIR__.'/Fixtures/'.$fixture), ['response_headers' => ['Content-Type: application/fhir+json']]);
        });

        return new AnnuaireSante($client, 'test-key');
    }
}
