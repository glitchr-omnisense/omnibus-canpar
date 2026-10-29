<?php

namespace Omnibus\Canpar;

use Omnibus\Canpar\Action\CancelAction;
use Omnibus\Canpar\Action\GetSlipAction;
use Omnibus\Canpar\Action\RatingAction;
use Omnibus\Canpar\Action\ShippingAction;
use Omnibus\Canpar\Action\TrackingAction;
use Omnibus\Config;
use Omnibus\GatewayFactory;
use Symfony\Component\HttpClient\HttpClient;

/**
 *   options:
 *     user_id: '%env(CANPAR_USER)%'           # the CanShip web services login
 *     password: '%env(CANPAR_PASSWORD)%'
 *     shipper_number: '%env(CANPAR_SHIPPER)%' # the account number
 *     sandbox: true
 *     rates: [...]                            # optional: configured prices instead of rateShipment
 *
 * No pickup points: Canpar delivers to the door.
 */
final class CanparGatewayFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnibus.factory_name' => 'canpar',
            'omnibus.factory_title' => 'Canpar',
            'omnibus.required_options' => ['user_id', 'password', 'shipper_number'],
            'sandbox' => false,
            'omnibus.api' => function (Config $c) {
                $http = $this->http ?? HttpClient::create();

                return new Api($http, (string) $c['user_id'], (string) $c['password'], (string) $c['shipper_number'], (bool) $c['sandbox']);
            },
            'omnibus.action.rating' => static fn (Config $c) => $c->get('rates') ? null : new RatingAction(),
            'omnibus.action.shipping' => new ShippingAction(),
            'omnibus.action.tracking' => new TrackingAction(),
            'omnibus.action.cancel' => new CancelAction(),
            'omnibus.action.get_slip' => new GetSlipAction(),
        ]);
    }
}
