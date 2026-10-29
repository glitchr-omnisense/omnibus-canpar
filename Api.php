<?php

namespace Omnibus\Canpar;

use Omnibus\Exception\CarrierException;
use Omnibus\Http\Redaction;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Canpar's CanShip web services (SOAP 1.1): every request carries the
 * account's user id and password inside its body; rating, shipping and
 * tracking are three endpoints.
 */
final class Api
{
    public const LIVE = 'https://canship.canpar.com/canshipws/services';
    public const TEST = 'https://sandbox.canpar.com/canshipws/services';
    private const NS_WS = 'http://ws.business.canshipws.canpar.com';
    private const NS_DTO = 'http://dto.canshipws.canpar.com';

    public const RATING = 'CanparRatingService';
    public const SHIPPING = 'CanparAddonsService';
    public const TRACKING = 'CanparTrackingService';

    public function __construct(
        private readonly HttpClientInterface $http,
        public readonly string $userId,
        private readonly string $password,
        public readonly string $shipperNumber,
        public readonly bool $sandbox = false,
        private readonly int $timeout = 20,
    ) {
    }

    /** One SOAP call: the operation, the <request> element's inner XML; back as the result element without namespaces. */
    public function call(string $service, string $operation, string $request): \SimpleXMLElement
    {
        $envelope = '<?xml version="1.0" encoding="utf-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ws="'.self::NS_WS.'" xmlns:xsd="'.self::NS_DTO.'">'
            .'<soapenv:Body><ws:'.$operation.'><ws:request><xsd:user_id>'.self::e($this->userId).'</xsd:user_id><xsd:password>'.self::e($this->password).'</xsd:password>'.$request.'</ws:request></ws:'.$operation.'></soapenv:Body></soapenv:Envelope>';
        try {
            $response = $this->http->request('POST', ($this->sandbox ? self::TEST : self::LIVE).'/'.$service, [
                'headers' => ['Content-Type' => 'text/xml; charset=utf-8', 'SOAPAction' => 'urn:'.$operation],
                'body' => $envelope,
                'timeout' => $this->timeout,
            ]);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (HttpExceptionInterface $e) {
            $secrets = $this->secrets();

            throw Redaction::withoutArguments(new CarrierException('canpar', 'Canpar request failed: '.Redaction::text($e->getMessage(), $secrets), null, Redaction::copy($e, $secrets)));
        }
        $xml = @simplexml_load_string(preg_replace(['/<(\/?)[\w-]+:/', '/\sxmlns(:\w+)?="[^"]*"/'], ['<$1', ''], $content));
        if (false === $xml) {
            throw Redaction::withoutArguments(new CarrierException('canpar', sprintf('Canpar answered HTTP %d with a body that is not SOAP.', $status)));
        }
        if (isset($xml->Body->Fault)) {
            throw Redaction::withoutArguments(new CarrierException('canpar', (string) ($xml->Body->Fault->faultstring ?? 'SOAP fault')));
        }
        $result = $xml->Body->children()[0]->return ?? null;
        if ($status >= 400 || null === $result) {
            throw Redaction::withoutArguments(new CarrierException('canpar', sprintf('HTTP %d', $status)));
        }
        if ('' !== trim((string) ($result->error ?? ''))) {
            throw Redaction::withoutArguments(new CarrierException('canpar', (string) $result->error));
        }

        return $result;
    }

    public static function e(string $s): string
    {
        return htmlspecialchars($s, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
    }

    /**
     * What a failure must not carry: the credentials, and what a request made of them.
     *
     * @return list<string>
     */
    private function secrets(string ...$more): array
    {
        return [$this->password, ...$more];
    }
}
