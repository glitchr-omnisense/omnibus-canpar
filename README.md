# omnibus/canpar

Canpar for [omnisense/omnibus](https://github.com/glitchr-omnisense/omnibus): rates, shipments with
their labels, tracking and voids - the CanShip web services (SOAP).

## Install and a first call

```sh
composer require omnibus/canpar
```

No account for this first call: Canpar's answer is recorded (`Omnibus\Testing\RecordedHttpClient`
answers in place of the API), so nothing leaves the process. With your own credentials, give the
factory your HTTP client (or none: it makes its own) and drop the recorded one.

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnibus\Canpar\CanparGatewayFactory;
use Omnibus\Testing\RecordedHttpClient;

// Canpar's answer, recorded: no account, and nothing leaves the process.
$http = new RecordedHttpClient([
    'body:trackByBarcode' => '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body><ns:trackByBarcodeResponse xmlns:ns="http://ws.business.canshipws.canpar.com">'
        .'<ns:return xmlns:ax="http://dto.canshipws.canpar.com"><ax:result><ax:barcode>D420000000012345</ax:barcode><ax:delivered_date_time>2026-10-02T10:15:00</ax:delivered_date_time>'
        .'<ax:events><ax:code>DEL</ax:code><ax:code_description_en>Delivered</ax:code_description_en><ax:local_date_time>2026-10-02T10:15:00</ax:local_date_time>'
        .'<ax:address><ax:city>TORONTO</ax:city><ax:province>ON</ax:province></ax:address></ax:events></ax:result></ns:return></ns:trackByBarcodeResponse></soapenv:Body></soapenv:Envelope>',
]);
$gateway = (new CanparGatewayFactory($http))->create(['user_id' => 'user@example.org', 'password' => 'pass', 'shipper_number' => '99999999', 'sandbox' => true]);

$tracking = $gateway->track('D420000000012345');
echo $tracking->status->value, ' - ', $tracking->latest()?->description, "\n";   // delivered - Delivered
```

A real call needs a Canpar account (the credentials below). The answer is shaped after Canpar's
published documentation; the package has not been run against its service yet.

## Configuration

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
