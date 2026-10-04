# omnistate/annuaire-sante

## Installation

```sh
composer require glitchr/omnistate omnistate/annuaire-sante
```

In a Symfony application, `Omnistate\Bridge\Symfony\OmnistateBundle` registers it when it is installed:

```yaml
# config/packages/omnistate.yaml
omnistate:
    annuaire_sante:
        api_key: '%env(ESANTE_API_KEY)%'
        # url: https://gateway.api.esante.gouv.fr/fhir/v2
```

```dotenv
# .env.local - never committed
ESANTE_API_KEY=...
```

## What is asked

| Call | Request |
|---|---|
| `professional($rpps)` | `GET /Practitioner?identifier={rpps}&_revinclude=PractitionerRole:practitioner&_include:iterate=PractitionerRole:organization` |
| `search($name, $postcode, $limit)` | `GET /Practitioner?name={name}&active=true&_count=…` with the same includes; the postcode filters on the workplaces' addresses |
| `facility($finess)` | `GET /Organization?identifier={finess}` |

The answer is a FHIR search `Bundle`; `professionals(array $bundle)` reads any such bundle you
fetched yourself.

## What is read

- **Practitioner**: the RPPS (identifier of type `RPPS`, or the `IDNPS` minus its leading 8), the usual
  name, the qualifications - the profession (nomenclature `TRE_G15-ProfessionSante`: 10 Médecin,
  60 Infirmier, 70 Masseur-kinésithérapeute, 50 Sage-femme...), the diplomas (`TRE_R48`), every other
  coded qualification as a specialty or know-how -, the telecoms (an e-mail marked `MSSANTE`, or under
  `.mssante.fr`, is a secure address), the languages.
- **PractitionerRole**: each is a `Workplace`: its organization, the mode of practice (`TRE_R23`:
  L liberal, S salaried, B volunteer), the function (`TRE_R21`), active unless its period ended.
- **Organization**: name, FINESS (`FINEG` a site, `FINEJ` the legal entity), types, address (the
  INSEE country code 99100 read as FR, the postcode stripped from the city), telecoms.

## Errors

`UnavailableException` - no key, the key refused (401/403), too many calls (429, with `retryAfter`),
the server down. None of them means "no such professional".

## Verified

The tests run on the examples of the ANS's implementation guide (fictional people) put in search
bundles. The request shape (the `_revinclude` / `_include:iterate` pair) follows the guide; it has not
been tried against the real API without a key.
