# omnibus/canpar

Canpar for [omnisense/omnibus](https://github.com/glitchr-omnisense/omnibus): rates, shipments with
their labels, tracking and voids, through Canpar's CanShip web services (SOAP 1.1). The envelopes
are written and read as XML over plain HTTP (`ext-soap` is not needed). The gateway calls:

| Operation | Service | Operation name |
|---|---|---|
| rate | `CanparRatingService` | `rateShipment`, once per service |
| ship | `CanparAddonsService` | `processShipment`, then `getLabels` |
| track | `CanparTrackingService` | `trackByBarcode` |
| cancel | `CanparTrackingService`, then `CanparAddonsService` | `trackByBarcode`, then `voidShipment` |

under `https://canship.canpar.com/canshipws/services` (live) or
`https://sandbox.canpar.com/canshipws/services` (`sandbox: true`), each with
`SOAPAction: urn:<operation>` and a 20-second timeout.

## Installation

```sh
composer require omnibus/canpar
```

Requires PHP 8.2, `omnisense/omnibus` (2.x) and `symfony/http-client` (6.4, 7 or 8); given no
HTTP client, the factory makes one with `HttpClient::create()`. In a Symfony application with
`Omnibus\Bridge\Symfony\OmnibusBundle`, the factory is registered as soon as the package is
installed.

## Credentials

- A Canpar account with CanShip web services access: the **user id** (an email address) and
  **password**, and the **shipper number** (the account number). The account manager gives the
  sandbox login.

The user id and password travel inside every request's body.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `user_id` | string | - | yes | the CanShip login |
| `password` | string | - | yes | its password |
| `shipper_number` | string | - | yes | the account number (`shipper_num`) |
| `sandbox` | bool | `false` | no | `true`: `sandbox.canpar.com` |
| `rates` | list | - | no | prices from configuration instead of `rateShipment` ([the rates option](https://github.com/glitchr-omnisense/omnibus/blob/2.x/docs/configuration.md#the-rates-option)): with it, `rateShipment` is not called |

Shipment options (`Shipment::$options`):

| Option | Read by | Default | |
|---|---|---|---|
| `services` | rate | `1`, `2`, `3` to Canada; `4`, `5` to the US; `6` elsewhere | the service types to price |
| `sender_province` | rate, ship | `''` | the sender's two-letter province or state |
| `recipient_province` | rate, ship | `''` | the recipient's two-letter province or state |
| `label_format` | ship | `PDF` | `PDF`, or `ZPL` for a thermal label |

The address model has no province: it comes from the two options.

## Capabilities

| Request | Supported |
|---|---|
| `Rating` | yes (`rateShipment`), or configured rates with `rates` |
| `Shipping` | yes |
| `Tracking` | yes |
| `Pickup` | no: Canpar delivers to the door |
| `GetSlip` | no |
| `Cancel` | yes (void) |
| `Paperless` | no |

## Behaviour

**Services.** The service is Canpar's service type; labels come from
`Omnibus\Canpar\Mapping::SERVICES`:

| Type | Label |
|---|---|
| `1` | Canpar Ground (the default to Canada) |
| `2` | Select |
| `3` | Overnight |
| `4` | USA |
| `5` | Select USA |
| `6` | International (the default elsewhere) |
| `7` | Select Letter |
| `8` | Overnight Letter |
| `9` | USA Letter |

Another type is labelled `Canpar <type>`.

**The shipment.** Rating and shipping send the same `shipment` element: the shipper number, the
shipping date and time (`Shipment::$shippingDate`, else now), the service type
(`Shipment::$service`, else the default above), centimetres and kilograms, an email notification
to the recipient when they have an email, the sender as pickup address and the recipient as
delivery address (name: the company, else the person; attention: the person; two address lines,
40 characters each; city; province option; country; postcode in capitals without spaces; phone
digits; email), one package per parcel (weight in kilograms with two decimals, at least 0.1;
dimensions when all three are given; the declared `value` when set), and the reference (40
characters).

**Rate.** One `rateShipment` per service type (`services`, else the default for the
destination, whatever `Shipment::$service` says), with the association, individual and invoice discounts applied. Each answer's
shipment gives one rate: its `total_charge` in `CAD`, `days` its `transit_time` (none when 0). A
service Canpar refuses is left out; when every service is refused, the last refusal is raised.
Rates come cheapest first; `toPickupPoint` is `false`.

**Ship.** `processShipment`; the tracking number is the first package's barcode, and the
shipment's `id` is kept for the label: `getLabels` with `thermal` true for `ZPL`. The label is the
first one returned, base64-decoded, `application/zpl` or `application/pdf`. When `getLabels`
fails, the booking still succeeds without content (a comment in `ShippingAction` says `GetSlip`
asks again; `GetSlip` is not supported). One `Label` carries the first package's barcode for the
whole shipment. `trackingUrl` is `https://www.canpar.com/en/tracking/track.htm?barcode=<number>`.
A response without an id or a barcode is a `CarrierException` (`Canpar booked no shipment.`).

**Track.** `trackByBarcode` with the shipment's tracking; the locale is not used: the English
description is taken (the French one when there is no English one). Events: `local_date_time`,
`city province` as location, Canpar's code as `code`; sorted oldest first. The status is the
latest event's, or `DELIVERED` when the answer has a `delivered_date_time`. An event's status, read
from its code and its English description in this order:

| Code | Or the description contains | `TrackingStatus` |
|---|---|---|
| `DEL` | `delivered` | `DELIVERED` |
| `OFD` | `out for delivery` | `OUT_FOR_DELIVERY` |
| | `available for pickup`, `held for pickup` | `AVAILABLE_FOR_PICKUP` |
| | `return` | `RETURNED` |
| | `attempt`, `exception`, `unable` | `EXCEPTION` |
| `MAN` | `manifest`, `shipment created` | `PENDING` |
| any other code | | `IN_TRANSIT` |
| no code | | `UNKNOWN` |

**Cancel.** Canpar voids by shipment id, not by barcode: `trackByBarcode` first finds the
shipment's id (`shipment_id`, else `id`); none gives `false`. Then `voidShipment` with that id:
`true` when it does not fail (its answer is not read further).

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnibus\Canpar\CanparGatewayFactory;
use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Symfony\Component\HttpClient\HttpClient;

$canpar = (new CanparGatewayFactory(HttpClient::create()))->create([
    'user_id' => getenv('CANPAR_USER'),
    'password' => getenv('CANPAR_PASSWORD'),
    'shipper_number' => getenv('CANPAR_SHIPPER'),
    'sandbox' => true,
]);

$shipment = new Shipment(
    new Address('Glitch Art', ['100 Queen St W'], 'M5H 2N2', 'Toronto', 'CA', company: 'Glitch Art', phone: '416-555-9876'),
    new Address('Ada Lovelace', ['1 Wellington St'], 'K1A 0A9', 'Ottawa', 'CA', email: 'ada@example.org'),
    [new Parcel(1500, 30, 20, 10, value: 4500)],
    service: '1',
    reference: 'CMD-1042',
    options: ['sender_province' => 'ON', 'recipient_province' => 'ON', 'label_format' => 'PDF'],
);

foreach ($canpar->rate($shipment) as $rate) {
    printf("%s %-14s %6.2f %s, %s days\n", $rate->service, $rate->label, $rate->amount / 100, $rate->currency, $rate->days ?? '?');
}

$label = $canpar->ship($shipment);
file_put_contents($label->trackingNumber.'.pdf', (string) $label->content);
echo $label->trackingNumber, ' ', $label->trackingUrl, "\n";

$tracking = $canpar->track($label->trackingNumber);
echo $tracking->status->value, ' ', $tracking->latest()?->location, "\n";

var_dump($canpar->cancel($label->trackingNumber));
```

In Symfony:

```yaml
omnibus:
    gateways:
        canpar:
            factory: canpar
            options:
                user_id: '%env(CANPAR_USER)%'
                password: '%env(CANPAR_PASSWORD)%'
                shipper_number: '%env(CANPAR_SHIPPER)%'
                sandbox: '%env(bool:CANPAR_SANDBOX)%'
```

## Errors

Every failure is an `Omnibus\Exception\CarrierException` whose `carrier` is `canpar`:

- an `error` in Canpar's answer: its text (Canpar gives no code);
- a SOAP fault: its `faultstring`;
- an HTTP status of 400 or more, or an answer without its `return` element: `HTTP <status>`;
- a body that is not XML: `Canpar answered HTTP <status> with a body that is not SOAP.`;
- a network error: `Canpar request failed: ...`;
- `Canpar booked no shipment.` when no id or barcode comes back.

A failed `getLabels` after the shipment is created is not raised (above). A missing `user_id`,
`password` or `shipper_number` is an `InvalidConfigException` when the gateway is built; `Pickup`,
`GetSlip` and `Paperless` raise `RequestNotSupportedException` before any call.

## Verified, and not

Tested offline: `Tests/CanparGatewayTest.php` (each service rated in its own call and the rates
cheapest first with their labels and transit days, the login in the body, the postcode without its
space, the weight in kilograms; a shipment processed and its label fetched with `thermal` false;
tracking with its location and the delivered date; the void through the shipment id; no pickup
points) and `Tests/CanparContractTest.php`, the family's conformance suite on recorded answers.
The example above was run in the core's harness on those recorded answers.

Not verified: the README says the package is built from Canpar's published CanShip documentation
and was not yet run against the sandbox; that needs the credentials above.

## Responsibility

An independent, unofficial client, not affiliated with Canpar Express (TFI International).
Whoever runs it complies with Canpar's terms ([canpar.com terms of
use](https://www.canpar.com/en/about/terms_of_use.htm)): see the README's Responsibility section.
