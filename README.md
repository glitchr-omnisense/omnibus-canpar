# omnibus/canpar

Canpar for [omnisense/omnibus](https://github.com/glitchr-omnisense/omnibus): rates, shipments with
their labels, tracking and voids - the CanShip web services (SOAP).

```php
$gateway = (new CanparGatewayFactory($http))->create($options);   // $http: the application's HTTP client - none given, the factory makes its own; the options below
```

No framework needed: the package requires `omnisense/omnibus` and `symfony/http-client`. In a
Symfony application, the same through the bundle's configuration:

```yaml
omnibus:
    gateways:
        canpar:
            factory: canpar
            options:
                user_id: '%env(CANPAR_USER)%'
                password: '%env(CANPAR_PASSWORD)%'
                shipper_number: '%env(CANPAR_SHIPPER)%'
                sandbox: true
                rates: [...]        # optional: configured prices instead of rateShipment
```

The service is Canpar's service type: 1 Ground, 2 Select, 3 Overnight, 4 USA, 5 Select USA,
6 International. Rating asks once per service (option `services` picks them). Shipment options:
`sender_province` and `recipient_province` (two letters), `label_format` (PDF, ZPL for thermal).
No pickup points: Canpar delivers to the door.

Credentials: a Canpar account with CanShip web services access (ask your account manager for the
sandbox login) and your shipper number.

Built from Canpar's published CanShip documentation and tested on recorded answers; not yet run
against the sandbox: that needs the credentials above.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Canpar Express (TFI International). It reaches their service on behalf of whoever runs
it, and it does not redistribute their data beyond the short excerpts recorded as test fixtures. Its
MIT licence covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with Canpar's terms ([canpar.com
terms of use](https://www.canpar.com/en/about/terms_of_use.htm)), for obtaining whatever they
require (an account, a key, a licence or subscription, an attribution or a citation, a rate limit to
respect), and for any use made of what it returns. The authors publish it free of charge, make no
commercial use of it, and accept no liability for how it is used (see [LICENSE](LICENSE)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
