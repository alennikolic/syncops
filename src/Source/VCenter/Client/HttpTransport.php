<?php
// src/Source/VCenter/Client/HttpTransport.php
//
// cURL transport prema jednom vCenteru. Zna za TLS, timeout-e, ponavljanje i
// broj poziva; ne zna nista o vSphere putanjama ni o dijalektima.
//
// Broj poziva se broji zato sto REST API trazi jedan poziv po VM-u (pa i dva,
// uz podatke iz gosta), pa je to podatak koji ide u discovery_run.api_calls i
// sluzi za procenu opterecenja vCentera.

declare(strict_types=1);

namespace SyncOps\Source\VCenter\Client;

use SensitiveParameter;
use SyncOps\Source\VCenter\VCenterException;

final class HttpTransport
{
    private const CONNECT_TIMEOUT = 10;

    /** HTTP statusi na kojima ima smisla pokusati ponovo. */
    private const RETRYABLE = [429, 502, 503, 504];

    private int $callCount = 0;

    public function __construct(
        private readonly string $baseUrl,
        private readonly bool $verifyPeer,
        private readonly string $host,
        private readonly int $timeout = 30,
        private readonly int $retries = 2,
    ) {
    }

    public function callCount(): int
    {
        return $this->callCount;
    }

    /**
     * @param list<array{0: string, 1: string}> $query Parovi naziv/vrednost;
     *        lista, a ne mapa, jer /api dijalekt ponavlja isti naziv za nizove.
     * @param array<string, string> $headers
     * @param array{0: string, 1: string}|null $basicAuth
     * @param list<int> $finalStatuses Statusi koji su konacan odgovor i NE SMEJU
     *        da se ponavljaju, iako su inace u listi za ponavljanje. Primer:
     *        guest/identity vraca 503 kada VMware Tools ne rade — to je trajno
     *        stanje masine, ne privremeno preopterecenje servera, pa bi
     *        ponavljanje samo trosilo po tri sekunde na svaku takvu masinu.
     */
    public function request(
        string $method,
        string $path,
        array $query = [],
        array $headers = [],
        ?string $body = null,
        #[SensitiveParameter] ?array $basicAuth = null,
        array $finalStatuses = [],
    ): Response {
        $url = $this->baseUrl . $path;

        if ($query !== []) {
            $url .= '?' . implode('&', array_map(
                static fn (array $pair): string => rawurlencode($pair[0]) . '=' . rawurlencode($pair[1]),
                $query
            ));
        }

        $attempt   = 0;
        $lastError = null;

        while (true) {
            $attempt++;
            $this->callCount++;

            $response = $this->send($method, $url, $headers, $body, $basicAuth, $lastError);

            if ($response !== null) {
                $retryable = in_array($response->status, self::RETRYABLE, true)
                    && !in_array($response->status, $finalStatuses, true);

                if (!$retryable) {
                    return $response;
                }
            }

            if ($attempt > $this->retries) {
                if ($response !== null) {
                    return $response;
                }

                throw $lastError ?? VCenterException::unreachable($this->host, 'nepoznata greska');
            }

            // 1s, 2s, 4s ...
            sleep(2 ** ($attempt - 1));
        }
    }

    /**
     * @param array<string, string> $headers
     * @param array{0: string, 1: string}|null $basicAuth
     */
    private function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        #[SensitiveParameter] ?array $basicAuth,
        ?VCenterException &$lastError,
    ): ?Response {
        $ch = curl_init();

        $headerLines = [];

        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        if ($body !== null) {
            $headerLines[] = 'Content-Type: application/json';
        }

        $responseHeaders = [];

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifyPeer,
            CURLOPT_SSL_VERIFYHOST => $this->verifyPeer ? 2 : 0,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_ACCEPT_ENCODING => '',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        if ($basicAuth !== null) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $basicAuth[0] . ':' . $basicAuth[1]);
        }

        $raw      = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno    = curl_errno($ch);
        $errorStr = curl_error($ch);

        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            $lastError = $this->translateCurlError($errno, $errorStr);

            return null;
        }

        return new Response($status, (string) $raw, $responseHeaders);
    }

    /**
     * Prevodi cURL gresku u poruku koja nesto znaci onome ko je cita.
     *
     * Kodovi su namerno brojevi a ne CURLE_* konstante: deo njih ne postoji u
     * svim PHP/cURL buildovima (CURLE_PEER_FAILED_VERIFICATION je primer), pa
     * bi referenca na konstantu obarala aplikaciju bas u trenutku kada treba
     * da prijavi problem sa sertifikatom.
     */
    private function translateCurlError(int $errno, string $message): VCenterException
    {
        return match ($errno) {
            // 35 SSL_CONNECT_ERROR, 51 PEER_FAILED_VERIFICATION,
            // 58 SSL_CERTPROBLEM, 59 SSL_CIPHER, 60 SSL_CACERT,
            // 77 SSL_CACERT_BADFILE, 83 SSL_ISSUER_ERROR
            35, 51, 58, 59, 60, 77, 83 => VCenterException::tls($this->host, $message),

            7  => VCenterException::unreachable($this->host, 'konekcija odbijena'),   // COULDNT_CONNECT
            6  => VCenterException::unreachable($this->host, 'ime se ne razresava'),  // COULDNT_RESOLVE_HOST
            28 => VCenterException::unreachable($this->host, 'isteklo vreme cekanja'),// OPERATION_TIMEDOUT
            52 => VCenterException::unreachable($this->host, 'prazan odgovor'),       // GOT_NOTHING
            56 => VCenterException::unreachable($this->host, 'veza prekinuta'),       // RECV_ERROR

            default => VCenterException::unreachable($this->host, $message),
        };
    }
}
