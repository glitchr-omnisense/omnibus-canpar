<?php

namespace Omnibus\Canpar\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\Canpar\Api;
use Omnibus\Canpar\Mapping;
use Omnibus\Model\Rate;
use Omnibus\Request\Rating;
use Omnibus\Request\Request;

/** rateShipment, once per service (option services: the service types to price; by default Ground, Select and Overnight, or International). */
final class RatingAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Rating;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Rating);
        $s = $request->shipment;
        $country = strtoupper($s->recipient->country);
        $services = $s->option('services', match ($country) { 'CA' => ['1', '2', '3'], 'US' => ['4', '5'], default => ['6'] });
        $rates = [];
        $failure = null;
        $answered = 0;
        foreach ($services as $service) {
            $priced = new \Omnibus\Model\Shipment($s->sender, $s->recipient, $s->parcels, (string) $service, $s->pickupPoint, $s->reference, $s->options, $s->shippingDate);
            try {
                $data = $this->api->call(Api::RATING, 'rateShipment', '<xsd:apply_association_discount>true</xsd:apply_association_discount><xsd:apply_individual_discount>true</xsd:apply_individual_discount><xsd:apply_invoice_discount>true</xsd:apply_invoice_discount>'.Mapping::shipment($priced, $this->api->shipperNumber));
            } catch (\Omnibus\Exception\CarrierException $e) {
                $failure = $e;
                continue;
            }
            ++$answered;
            $shipment = $data->processShipmentResult->shipment ?? null;
            if (null === $shipment) {
                continue;
            }
            $days = isset($shipment->transit_time) ? (int) $shipment->transit_time : null;
            $rates[] = new Rate('canpar', (string) $service, Mapping::SERVICES[(string) $service] ?? 'Canpar '.$service, (int) round(((float) $shipment->total_charge) * 100), 'CAD', $days ?: null);
        }
        if (0 === $answered && null !== $failure) {
            throw $failure; // every service refused: the carrier failed, not "no rate"
        }
        usort($rates, static fn (Rate $a, Rate $b) => $a->amount <=> $b->amount);
        $request->setResult($rates);
    }
}
