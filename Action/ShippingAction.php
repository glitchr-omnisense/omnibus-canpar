<?php

namespace Omnibus\Canpar\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\Canpar\Api;
use Omnibus\Canpar\Mapping;
use Omnibus\Exception\CarrierException;
use Omnibus\Model\Label;
use Omnibus\Request\Request;
use Omnibus\Request\Shipping;

/** processShipment, then getLabels for its label (PDF; thermal ZPL with option label_format). */
final class ShippingAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Shipping;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Shipping);
        $s = $request->shipment;
        $thermal = 'ZPL' === strtoupper((string) $s->option('label_format', 'PDF'));
        $data = $this->api->call(Api::SHIPPING, 'processShipment', Mapping::shipment($s, $this->api->shipperNumber));
        $shipment = $data->processShipmentResult->shipment ?? null;
        $id = (string) ($shipment->id ?? '');
        $number = (string) ($shipment->packages[0]->barcode ?? $shipment->packages->barcode ?? '');
        if ('' === $id || '' === $number) {
            throw new CarrierException('canpar', 'Canpar booked no shipment.');
        }
        $content = null;
        try {
            $content = GetSlipAction::fetch($this->api, $id, $thermal);
        } catch (CarrierException) {
            // The shipment is processed; slip() fetches its label again.
        }
        $request->setResult(new Label('canpar', $number, $content, $thermal ? Label::ZPL : Label::PDF, null, self::trackingUrl($number)));
    }

    public static function trackingUrl(string $number): string
    {
        return 'https://www.canpar.com/en/tracking/track.htm?barcode='.rawurlencode($number);
    }
}
