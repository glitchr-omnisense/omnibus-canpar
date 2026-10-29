<?php

namespace Omnibus\Canpar\Tests;

use Omnibus\Canpar\CanparGatewayFactory;
use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Omnibus\Model\TrackingStatus;
use Omnibus\Request\Pickup;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CanparGatewayTest extends TestCase
{
    private array $calls = [];

    private static function shipment(): Shipment
    {
        return new Shipment(new Address('Glitch Art', ['123 Rue Sainte-Catherine'], 'H2X 1K4', 'Montréal', 'CA', phone: '514 555 1234'), new Address('Alex Martin', ['100 Queen St W'], 'M5H 2N2', 'Toronto', 'CA'), [new Parcel(1500, 30, 20, 10)], reference: 'ORDER-1042', options: ['sender_province' => 'QC', 'recipient_province' => 'ON']);
    }

    private function gateway(): \Omnibus\GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertStringStartsWith('https://sandbox.canpar.com/canshipws/services/', $url);
            $body = (string) $options['body'];
            $this->calls[] = [$url, $body];
            $wrap = static fn (string $op, string $inner) => new MockResponse('<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body><ns:'.$op.'Response xmlns:ns="http://ws.business.canshipws.canpar.com"><ns:return xmlns:ax="http://dto.canshipws.canpar.com">'.$inner.'</ns:return></ns:'.$op.'Response></soapenv:Body></soapenv:Envelope>');
            preg_match('/<xsd:service_type>(\d)<\/xsd:service_type>/', $body, $m);
            $service = $m[1] ?? '1';

            return match (true) {
                str_contains($body, 'rateShipment') => $wrap('rateShipment', '<ax:error xsi:nil="true" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"/><ax:processShipmentResult><ax:shipment><ax:service_type>'.$service.'</ax:service_type><ax:total_charge>'.['1' => '12.80', '2' => '18.40', '3' => '25.10'][$service].'</ax:total_charge><ax:transit_time>'.['1' => '2', '2' => '1', '3' => '1'][$service].'</ax:transit_time></ax:shipment></ax:processShipmentResult>'),
                str_contains($body, 'processShipment') => $wrap('processShipment', '<ax:error xsi:nil="true" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"/><ax:processShipmentResult><ax:shipment><ax:id>70012345</ax:id><ax:packages><ax:barcode>D420000000012345</ax:barcode></ax:packages></ax:shipment></ax:processShipmentResult>'),
                str_contains($body, 'getLabels') => $wrap('getLabels', '<ax:error xsi:nil="true" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"/><ax:labels>'.base64_encode('%PDF-1.4 canpar').'</ax:labels>'),
                str_contains($body, 'trackByBarcode') => $wrap('trackByBarcode', '<ax:error xsi:nil="true" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"/><ax:result><ax:barcode>D420000000012345</ax:barcode><ax:shipment_id>70012345</ax:shipment_id><ax:delivered_date_time>2026-10-02T10:15:00</ax:delivered_date_time><ax:events><ax:code>DEL</ax:code><ax:code_description_en>Delivered</ax:code_description_en><ax:local_date_time>2026-10-02T10:15:00</ax:local_date_time><ax:address><ax:city>TORONTO</ax:city><ax:province>ON</ax:province></ax:address></ax:events><ax:events><ax:code>PIK</ax:code><ax:code_description_en>Picked up</ax:code_description_en><ax:local_date_time>2026-10-01T16:00:00</ax:local_date_time><ax:address><ax:city>MONTREAL</ax:city><ax:province>QC</ax:province></ax:address></ax:events></ax:result>'),
                str_contains($body, 'voidShipment') => $wrap('voidShipment', '<ax:error xsi:nil="true" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"/>'),
                default => $wrap('rateShipment', '<ax:error>Unknown operation</ax:error>'),
            };
        });

        return (new CanparGatewayFactory($http))->create(['user_id' => 'user@example.org', 'password' => 'pass', 'shipper_number' => '99999999', 'sandbox' => true]);
    }

    public function testEachServiceIsRatedAndTheyComeCheapestFirst(): void
    {
        $rates = $this->gateway()->rate(self::shipment());
        self::assertSame(['1', '2', '3'], array_map(fn ($r) => $r->service, $rates));
        self::assertSame(1280, $rates[0]->amount);
        self::assertSame('Canpar Ground', $rates[0]->label);
        self::assertSame(1, $rates[1]->days);
        self::assertCount(3, $this->calls);
        self::assertStringContainsString('<xsd:user_id>user@example.org</xsd:user_id>', $this->calls[0][1]);
        self::assertStringContainsString('<xsd:postal_code>M5H2N2</xsd:postal_code>', $this->calls[0][1]);
        self::assertStringContainsString('<xsd:reported_weight>1.50</xsd:reported_weight>', $this->calls[0][1]);
    }

    public function testAShipmentIsProcessedAndLabelled(): void
    {
        $label = $this->gateway()->ship(self::shipment());
        self::assertSame('D420000000012345', $label->trackingNumber);
        self::assertSame('%PDF-1.4 canpar', $label->content);
        self::assertStringContainsString('<xsd:id>70012345</xsd:id><xsd:thermal>false</xsd:thermal>', $this->calls[1][1]);
    }

    public function testTrackingAndVoid(): void
    {
        $gateway = $this->gateway();
        $tracking = $gateway->track('D420000000012345');
        self::assertSame(TrackingStatus::DELIVERED, $tracking->status);
        self::assertSame('Picked up', $tracking->events[0]->description);
        self::assertSame('TORONTO ON', $tracking->latest()->location);
        self::assertTrue($gateway->cancel('D420000000012345'));
        self::assertStringContainsString('<xsd:id>70012345</xsd:id>', end($this->calls)[1]);
        self::assertFalse($gateway->supports(Pickup::class));
    }

    /** Canpar's getLabels takes the shipment id, which trackByBarcode gives for the barcode: slip() fetches the label again. */
    public function testALabelIsFetchedAgainThroughItsShipmentId(): void
    {
        $label = $this->gateway()->slip('D420000000012345');
        self::assertSame('D420000000012345', $label->trackingNumber);
        self::assertSame('%PDF-1.4 canpar', $label->content);
        self::assertStringContainsString('<xsd:id>70012345</xsd:id>', end($this->calls)[1]);
    }
}
