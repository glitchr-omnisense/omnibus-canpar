<?php

namespace Omnibus\Canpar\Tests;

use Omnibus\Canpar\CanparGatewayFactory;
use Omnibus\GatewayFactoryInterface;
use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Omnibus\Testing\GatewayContractTests;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** The conformance suite on Canpar's recorded answers (CanShip web services, sandbox). */
final class CanparContractTest extends TestCase
{
    use GatewayContractTests;

    protected function gatewayFactory(HttpClientInterface $http): GatewayFactoryInterface
    {
        return new CanparGatewayFactory($http);
    }

    protected function gatewayOptions(): array
    {
        return ['user_id' => 'user@example.org', 'password' => 'pass', 'shipper_number' => '99999999', 'sandbox' => true];
    }

    protected function contractShipment(): Shipment
    {
        return new Shipment(new Address('Glitch Art', ['123 Rue Sainte-Catherine'], 'H2X 1K4', 'Montréal', 'CA', phone: '514 555 1234'), new Address('Alex Martin', ['100 Queen St W'], 'M5H 2N2', 'Toronto', 'CA'), [new Parcel(1500, 30, 20, 10)], reference: 'ORDER-1042', options: ['sender_province' => 'QC', 'recipient_province' => 'ON']);
    }

    protected function contractTrackingNumber(): string
    {
        return 'D420000000012345';
    }

    protected function recordedAnswers(): array
    {
        $soap = static fn (string $operation, string $inner) => '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body><ns:'.$operation.'Response xmlns:ns="http://ws.business.canshipws.canpar.com"><ns:return xmlns:ax="http://dto.canshipws.canpar.com"><ax:error xsi:nil="true" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"/>'.$inner.'</ns:return></ns:'.$operation.'Response></soapenv:Body></soapenv:Envelope>';

        return [
            'body:rateShipment' => static function (string $method, string $url, array $options) use ($soap): string {
                $service = preg_match('/<xsd:service_type>(\d)<\/xsd:service_type>/', (string) $options['body'], $m) ? $m[1] : '1';

                return $soap('rateShipment', '<ax:processShipmentResult><ax:shipment><ax:service_type>'.$service.'</ax:service_type><ax:total_charge>'.(['1' => '12.80', '2' => '18.40', '3' => '25.10'][$service] ?? '30.00').'</ax:total_charge><ax:transit_time>'.(['1' => '2'][$service] ?? '1').'</ax:transit_time></ax:shipment></ax:processShipmentResult>');
            },
            'body:processShipment' => $soap('processShipment', '<ax:processShipmentResult><ax:shipment><ax:id>70012345</ax:id><ax:packages><ax:barcode>D420000000012345</ax:barcode></ax:packages></ax:shipment></ax:processShipmentResult>'),
            'body:getLabels' => $soap('getLabels', '<ax:labels>'.base64_encode('%PDF-1.4 canpar').'</ax:labels>'),
            'body:trackByBarcode' => $soap('trackByBarcode', '<ax:result><ax:barcode>D420000000012345</ax:barcode><ax:shipment_id>70012345</ax:shipment_id><ax:delivered_date_time>2026-10-02T10:15:00</ax:delivered_date_time><ax:events><ax:code>DEL</ax:code><ax:code_description_en>Delivered</ax:code_description_en><ax:local_date_time>2026-10-02T10:15:00</ax:local_date_time><ax:address><ax:city>TORONTO</ax:city><ax:province>ON</ax:province></ax:address></ax:events><ax:events><ax:code>PIK</ax:code><ax:code_description_en>Picked up</ax:code_description_en><ax:local_date_time>2026-10-01T16:00:00</ax:local_date_time><ax:address><ax:city>MONTREAL</ax:city><ax:province>QC</ax:province></ax:address></ax:events></ax:result>'),
            'body:voidShipment' => $soap('voidShipment', ''),
        ];
    }
}
