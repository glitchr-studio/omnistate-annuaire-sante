# omnistate/annuaire-sante

French health professionals and facilities from the [Annuaire Santé](https://annuaire.sante.fr) of the
Agence du numérique en santé, through its [FHIR API](https://ansforge.github.io/annuaire-sante-fhir-documentation/)
(version 2): a professional by RPPS number - profession, specialties, diplomas, where they practise and
how, their MSSanté addresses - and a facility by FINESS number. For [glitchr/omnistate](https://github.com/glitchr-studio/omnistate).

```php
$registry = new AnnuaireSante($httpClient, $_ENV['ESANTE_API_KEY']);
$professional = $registry->professional('10003461033');      // or its IDNPS, 810003461033
$professional->name(), $professional->profession /* Médecin */, $professional->specialties,
$professional->workplaces /* name, address, FINESS, liberal|salaried */, $professional->secureEmails;
$registry->search('Martin', postcode: '67');
$registry->facility('580008803');
```

The key is free: an account on the ANS's [Gravitee portal](https://portal.api.esante.gouv.fr), a
subscription to "API Annuaire Santé en libre accès"; it goes in the `ESANTE-API-KEY` header. 17 calls a
second. There is no sandbox: the API answers the real register. Without a key, every call throws
`UnavailableException`.

Documentation: [docs/](docs/index.md). License: LGPL-3.0-or-later.
