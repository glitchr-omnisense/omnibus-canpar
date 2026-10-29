<?php

namespace Omnibus\Canpar\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\Canpar\Api;
use Omnibus\Exception\CarrierException;
use Omnibus\Model\Label;
use Omnibus\Request\GetSlip;
use Omnibus\Request\Request;

/**
 * The label of a shipment processed earlier: trackByBarcode gives the barcode's shipment id
 * (as cancel() finds it), getLabels its label (PDF; thermal ZPL when asked for).
 */
final class GetSlipAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof GetSlip;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof GetSlip);
        $tracked = $this->api->call(Api::TRACKING, 'trackByBarcode', '<xsd:barcode>'.Api::e($request->trackingNumber).'</xsd:barcode><xsd:track_shipment>true</xsd:track_shipment>');
        $id = (string) ($tracked->result->shipment_id ?? $tracked->result->id ?? '');
        if ('' === $id) {
            throw new CarrierException('canpar', sprintf('Canpar knows no shipment for %s.', $request->trackingNumber));
        }
        $thermal = Label::ZPL === $request->format;
        $content = self::fetch($this->api, $id, $thermal);
        if (null === $content) {
            throw new CarrierException('canpar', sprintf('Canpar gave no label for %s.', $request->trackingNumber));
        }
        $request->setResult(new Label('canpar', $request->trackingNumber, $content, $thermal ? Label::ZPL : Label::PDF, null, ShippingAction::trackingUrl($request->trackingNumber)));
    }

    /** getLabels for a shipment id: its first label, base64-decoded, or null when Canpar sends none. */
    public static function fetch(Api $api, string $id, bool $thermal): ?string
    {
        $labels = $api->call(Api::SHIPPING, 'getLabels', '<xsd:id>'.Api::e($id).'</xsd:id><xsd:thermal>'.($thermal ? 'true' : 'false').'</xsd:thermal>');
        $encoded = (string) ($labels->labels[0] ?? $labels->labels ?? '');

        return '' !== $encoded ? (base64_decode($encoded, true) ?: null) : null;
    }
}
