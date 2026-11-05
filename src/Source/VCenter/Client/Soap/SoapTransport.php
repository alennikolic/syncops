<?php
// src/Source/VCenter/Client/Soap/SoapTransport.php
//
// Slanje SOAP zahteva na /sdk krajnju tacku vCentera.
//
// Zasto rucno a ne preko PHP-ovog SoapClient-a: vSphere WSDL se sa njim lose
// slaze. SoapClient salje xsi:type prefiks koji vCenter odbija, pogresno
// postavlja SOAPAction zaglavlje (pa javlja da metoda ne postoji), a sam WSDL
// je ogroman i dugo se parsira. Rucno sklopljen XML je ovde kraci put.
//
// Dve stvari koje SOAP trazi a REST nije:
//   1. Kolacic sesije. vCenter posle Login-a vraca vmware_soap_session i
//      ocekuje ga u svakom narednom zahtevu.
//   2. SOAPAction sa verzijom API-ja. Bez tacne verzije vCenter za novije
//      metode javlja da ne postoje, pa se verzija cita iz ServiceContent-a i
//      koristi od drugog poziva nadalje.

declare(strict_types=1);

namespace SyncOps\Source\VCenter\Client\Soap;

use SyncOps\Source\VCenter\VCenterException;

// Nije final da bi se u testu mogao podmetnuti transport koji vraca snimljen
// odgovor umesto da zove vCenter. Ista odluka kao kod ClientFactory.
class SoapTransport
{
    private const CONNECT_TIMEOUT = 10;

    /** Kolacic sesije, dobijen posle Login-a. */
    private ?string $sessionCookie = null;

    /** Verzija API-ja za SOAPAction; postavlja se iz ServiceContent-a. */
    private ?string $apiVersion = null;

    private int $callCount = 0;

    public function __construct(
        private readonly string $baseUrl,
        private readonly bool $verifyPeer,
        private readonly string $host,
        private readonly int $timeout = 120,
    ) {
    }

    public function callCount(): int
    {
        return $this->callCount;
    }

    public function setApiVersion(?string $version): void
    {
        $this->apiVersion = $version;
    }

    public function hasSession(): bool
    {
        return $this->sessionCookie !== null;
    }

    public function forgetSession(): void
    {
        $this->sessionCookie = null;
    }

    /**
     * Posalji telo SOAP zahteva i vrati sirov XML odgovora.
     *
     * @param string $body Sadrzaj <soapenv:Body>, bez omotaca
     */
    public function call(string $operation, string $body): string
    {
        $envelope = $this->envelope($body);

        $headers = [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: "' . ($this->apiVersion === null ? 'urn:vim25' : 'urn:vim25/' . $this->apiVersion) . '"',
            'Expect:',   // bez ovoga cURL salje "Expect: 100-continue" i ceka
        ];

        if ($this->sessionCookie !== null) {
            $headers[] = 'Cookie: ' . $this->sessionCookie;
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->baseUrl . '/sdk',
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $envelope,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifyPeer,
            CURLOPT_SSL_VERIFYHOST => $this->verifyPeer ? 2 : 0,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line): int {
                $this->captureCookie($line);

                return strlen($line);
            },
        ]);

        $this->callCount++;

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);

        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            throw $this->translateCurlError($errno, $error);
        }

        $xml = (string) $raw;

        // vCenter gresku vraca kao soapenv:Fault, i to sa statusom 500.
        if ($status === 500 || str_contains($xml, 'Fault>')) {
            throw $this->translateFault($operation, $xml, $status);
        }

        if ($status < 200 || $status >= 300) {
            throw VCenterException::unexpected($status, '/sdk (' . $operation . ')');
        }

        return $xml;
    }

    // -----------------------------------------------------------------

    private function envelope(string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soapenv:Envelope'
            . ' xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"'
            . ' xmlns:xsd="http://www.w3.org/2001/XMLSchema"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<soapenv:Body>' . $body . '</soapenv:Body>'
            . '</soapenv:Envelope>';
    }

    private function captureCookie(string $headerLine): void
    {
        if (stripos($headerLine, 'Set-Cookie:') !== 0) {
            return;
        }

        $value = trim(substr($headerLine, 11));
        $pair  = explode(';', $value, 2)[0];

        if (str_starts_with($pair, 'vmware_soap_session')) {
            $this->sessionCookie = $pair;
        }
    }

    private function translateFault(string $operation, string $xml, int $status): VCenterException
    {
        $message = '';

        if (preg_match('#<faultstring[^>]*>(.*?)</faultstring>#s', $xml, $m) === 1) {
            $message = trim(html_entity_decode(strip_tags($m[1])));
        }

        // Prepoznati tip greske iz detalja, kada ga ima.
        $isAuth = str_contains($xml, 'InvalidLogin')
            || str_contains($xml, 'NotAuthenticated');

        if ($isAuth) {
            return VCenterException::badCredentials();
        }

        if (str_contains($xml, 'NoPermission')) {
            return VCenterException::forbidden('citanje hardverskih podataka o hostovima');
        }

        return new VCenterException(
            sprintf(
                'vCenter je na SOAP pozivu %s vratio gresku%s',
                $operation,
                $message === '' ? '.' : ': ' . mb_substr($message, 0, 300)
            ),
            $status
        );
    }

    /**
     * Brojevi a ne CURLE_* konstante, jer
     * deo njih ne postoji u svim PHP buildovima.
     */
    private function translateCurlError(int $errno, string $message): VCenterException
    {
        return match ($errno) {
            35, 51, 58, 59, 60, 77, 83 => VCenterException::tls($this->host, $message),
            7  => VCenterException::unreachable($this->host, 'konekcija odbijena'),
            6  => VCenterException::unreachable($this->host, 'ime se ne razresava'),
            28 => VCenterException::unreachable($this->host, 'isteklo vreme cekanja'),
            52 => VCenterException::unreachable($this->host, 'prazan odgovor'),
            56 => VCenterException::unreachable($this->host, 'veza prekinuta'),
            default => VCenterException::unreachable($this->host, $message),
        };
    }
}
