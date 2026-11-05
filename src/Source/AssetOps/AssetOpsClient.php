<?php
// src/Source/AssetOps/AssetOpsClient.php
//
// Klijent za AssetOPS REST API.
//
// Cetiri stvari u kojima se AssetOPS razlikuje od vecine REST API-ja, i zbog
// kojih ovaj klijent postoji umesto golog cURL-a:
//
//  1. Prijava je "Authorization: Api-Key <kljuc>", NE Bearer. Ako se kljuc
//     posalje kao Bearer, server ga pokusa procitati kao JWT i vrati poruku
//     koja zvuci kao da je kljuc istekao.
//
//  2. HTTP 200 NIJE potvrda uspeha. Kada server odbije parametar, vrati 200
//     sa "data": null. Zato je provera uspeha: 2xx I telo je JSON I data
//     postoji i nije null.
//
//  3. Nepoznat parametar se tiho ignorise. "?page=2" vraca HTTP 200 i PRVU
//     stranu. Zato se posle svakog stranicenja proverava da je current_page
//     stvarno onaj koji je trazen.
//
//  4. Stranicenje je sa crticom (current-page, items-per-page), a filteri sa
//     donjom crtom (created_at_from). Mesana konvencija.
//
// Posledica druge i trece tacke zajedno: pogresno napisan upit ne vraca
// gresku nego prazan ili nepotpun spisak. U sinhronizaciji CMDB-a prazan
// spisak znaci "sve je nestalo", pa su te provere ovde obavezne, a ne
// opreznost.

declare(strict_types=1);

namespace SyncOps\Source\AssetOps;

use SensitiveParameter;

class AssetOpsClient
{
    private const CONNECT_TIMEOUT = 10;

    /** Podrazumevana velicina strane. Provereno do 100; maksimum nije poznat. */
    public const PAGE_SIZE = 100;

    /** Gornja granica broja strana, da greska u logici ne postane beskonacna petlja. */
    private const MAX_PAGES = 500;

    private int $callCount = 0;

    public function __construct(
        private readonly string $baseUrl,
        #[SensitiveParameter] private readonly string $apiKey,
        private readonly bool $verifyPeer = false,
        private readonly string $host = '',
        private readonly int $timeout = 30,
    ) {
    }

    public function callCount(): int
    {
        return $this->callCount;
    }

    /**
     * Da kljuc ne procuri kroz var_dump ili ispis izuzetka.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'apiKey' => '***', 'verifyPeer' => $this->verifyPeer];
    }

    // -----------------------------------------------------------------
    // Citanje
    // -----------------------------------------------------------------

    /**
     * GET koji vraca sadrzaj polja "data".
     *
     * @param array<string, scalar> $query
     * @return array<string, mixed>|list<mixed>
     */
    public function get(string $path, array $query = []): array
    {
        $body = $this->request($path, $query);

        // Ovde se primenjuje pravilo iz tacke 2: 200 nije dovoljno.
        if (!array_key_exists('data', $body) || $body['data'] === null) {
            throw AssetOpsException::rejected($path, http_build_query($query));
        }

        return is_array($body['data']) ? $body['data'] : ['value' => $body['data']];
    }

    /**
     * Ukupan broj zapisa na ruti, jednim pozivom.
     *
     * Trazi se jedna stavka po strani: zanima nas total_items, ne sadrzaj.
     *
     * PAZNJA: na /api/locations/ total_items broji samo KORENSKE lokacije,
     * jer je odgovor stablo. Tamo ovaj broj nije broj lokacija.
     */
    public function countOf(string $path): int
    {
        $data = $this->get($path, ['items-per-page' => 1]);

        return (int) ($data['total_items'] ?? 0);
    }

    /**
     * Procitaj sve zapise sa jedne liste, kroz stranicenje.
     *
     * Tri provere, sve tri zato sto AssetOPS odbijen parametar ne prijavljuje:
     *   - petlja ide po total_pages iz odgovora, ne po pretpostavci
     *   - current_page mora da bude onaj koji je trazen
     *   - na kraju broj procitanih mora da dostigne total_items
     *
     * Treca nije suvisna: tiho nepotpuno citanje je gore od pada, jer zapisi
     * koji nisu procitani u CMDB-u izgledaju kao zapisi koji su nestali.
     *
     * @param array<string, scalar> $query dodatni filteri
     * @return list<array<string, mixed>>
     */
    public function listAll(string $path, array $query = [], int $perPage = self::PAGE_SIZE): array
    {
        $page  = 1;
        $all   = [];
        $total = null;

        while ($page <= self::MAX_PAGES) {
            $data = $this->get($path, $query + [
                'current-page'   => $page,
                'items-per-page' => $perPage,
            ]);

            $current = (int) ($data['current_page'] ?? 0);

            if ($current !== $page) {
                throw AssetOpsException::paginationIgnored($path, $page, $current);
            }

            $items = $data['items'] ?? [];
            $all   = array_merge($all, is_array($items) ? $items : []);
            $total ??= (int) ($data['total_items'] ?? 0);

            if ($page >= (int) ($data['total_pages'] ?? 1) || $items === []) {
                break;
            }

            $page++;
        }

        if ($total !== null && count($all) < $total) {
            throw AssetOpsException::incomplete($path, count($all), $total);
        }

        /** @var list<array<string, mixed>> $all */
        return $all;
    }

    // -----------------------------------------------------------------
    // Upis
    // -----------------------------------------------------------------

    /**
     * Kreiranje. Vraca sadrzaj polja "data" — svedeni zapis, ne ceo CI.
     *
     * AssetOPS na uspesno kreiranje vraca 200, ne 201, pa se uspeh ne sme
     * proveravati poklapanjem na tacan kod. Odgovor NE sadrzi poslata polja
     * kao sto su serial_number, status, tags i attribute_values — da bi se
     * znalo sta je stvarno upisano, zapis se mora procitati ponovo.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body): array
    {
        $response = $this->request($path, [], 'POST', $body);

        if (!array_key_exists('data', $response) || $response['data'] === null) {
            throw AssetOpsException::writeRejected('POST', $path, $this->message($response));
        }

        /** @var array<string, mixed> $data */
        $data = is_array($response['data']) ? $response['data'] : ['value' => $response['data']];

        return $data;
    }

    /**
     * Izmena. Na samom zapisu PATCH ne postoji — samo GET, PUT i DELETE.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function put(string $path, array $body): array
    {
        $response = $this->request($path, [], 'PUT', $body);

        if (!array_key_exists('data', $response) || $response['data'] === null) {
            throw AssetOpsException::writeRejected('PUT', $path, $this->message($response));
        }

        /** @var array<string, mixed> $data */
        $data = is_array($response['data']) ? $response['data'] : ['value' => $response['data']];

        return $data;
    }

    /**
     * Brisanje.
     *
     * JEDINI poziv kod kog "data": null znaci uspeh, a ne odbijen upit:
     * uspesno brisanje vraca 200 sa data: null i message "Item deleted.".
     * Zato se ovde uspeh cita iz polja success.
     */
    public function delete(string $path): bool
    {
        $response = $this->request($path, [], 'DELETE');

        return ($response['success'] ?? false) === true;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function message(array $response): string
    {
        $message = $response['message'] ?? $response['detail'] ?? '';

        return is_string($message) ? $message : '';
    }

    // -----------------------------------------------------------------
    // HTTP
    // -----------------------------------------------------------------

    /**
     * @param array<string, scalar> $query
     * @param array<string, mixed>|null $body
     * @return array<string, mixed> celo telo odgovora, sa omotacem
     */
    protected function request(string $path, array $query = [], string $method = 'GET', ?array $body = null): array
    {
        $url = $this->baseUrl . $path . ($query === [] ? '' : '?' . http_build_query($query));

        $headers = [
            // Api-Key, ne Bearer. Vidi komentar na vrhu klase.
            'Authorization: Api-Key ' . $this->apiKey,
            'Accept: application/json',
        ];

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifyPeer,
            CURLOPT_SSL_VERIFYHOST => $this->verifyPeer ? 2 : 0,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        if ($body !== null) {
            curl_setopt(
                $ch,
                CURLOPT_POSTFIELDS,
                (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        $this->callCount++;

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);

        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            throw $this->translateCurlError($errno, $error);
        }

        return $this->decode($path, (string) $raw, $status);
    }

    /**
     * Celo telo odgovora, bez provere polja data.
     *
     * Potrebno merenju ponasanja upisa, koje gleda i status i message i
     * sirov oblik odgovora — dakle upravo ono sto ostale metode sakrivaju.
     *
     * @param array<string, scalar> $query
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    public function raw(string $path, string $method = 'GET', ?array $body = null, array $query = []): array
    {
        return $this->request($path, $query, $method, $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $path, string $raw, int $status): array
    {
        $body = json_decode($raw, true);

        if ($status === 401) {
            // Dve razlicite 401 poruke znace dve razlicite stvari.
            throw (is_array($body) && ($body['code'] ?? null) === 'token_not_valid')
                ? AssetOpsException::looksLikeJwt()
                : AssetOpsException::noCredentials();
        }

        if ($status === 403) {
            throw AssetOpsException::forbidden($path);
        }

        if (!is_array($body)) {
            throw AssetOpsException::notJson($path, $raw);
        }

        if ($status < 200 || $status >= 300) {
            throw AssetOpsException::unexpected($status, $path, $this->errorDetail($body));
        }

        return $body;
    }

    /**
     * Sta je tacno zamerio server.
     *
     * Kod 422 AssetOPS imenuje polje koje smeta, ali ne u "detail" nego u
     * "data", kao mapu polje => spisak poruka. Bez ovoga ostaje samo gola
     * sifra statusa, a ona ne kaze nista: sve greske u upisu izgledaju isto.
     *
     * @param array<string, mixed> $body
     */
    private function errorDetail(array $body): string
    {
        $parts = [];

        foreach (['detail', 'message'] as $key) {
            $value = $body[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $parts[] = trim($value);

                break;
            }
        }

        $data = $body['data'] ?? null;

        if (is_array($data)) {
            foreach ($data as $field => $errors) {
                // Niz bez imenovanih kljuceva nije mapa gresaka po polju.
                if (is_int($field)) {
                    continue;
                }

                $text = $this->flatten($errors);

                if ($text !== '') {
                    $parts[] = $field . ': ' . $text;
                }
            }
        }

        // Dovoljno da se vidi sta fali, a da poruka ostane citljiva u redu
        // dnevnika; celo telo greske ionako stoji uz zapis.
        return implode(' — ', array_slice($parts, 0, 6));
    }

    private function flatten(mixed $value): string
    {
        if (is_array($value)) {
            $parts = [];

            foreach ($value as $item) {
                $parts[] = $this->flatten($item);
            }

            return trim(implode(' ', array_filter($parts)));
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return $value === null ? '' : trim((string) $value);
    }

    /**
     * Brojevi a ne CURLE_* konstante: deo njih ne postoji u svim PHP
     * buildovima, pa bi upotreba konstante obarala rad na najverovatnijoj
     * gresci — self-signed sertifikatu.
     */
    private function translateCurlError(int $errno, string $message): AssetOpsException
    {
        $host = $this->host === '' ? $this->baseUrl : $this->host;

        return match ($errno) {
            35, 51, 58, 59, 60, 77, 83 => AssetOpsException::tls($host, $message),
            7  => AssetOpsException::unreachable($host, 'konekcija odbijena'),
            6  => AssetOpsException::unreachable($host, 'ime se ne razresava'),
            28 => AssetOpsException::unreachable($host, 'isteklo vreme cekanja'),
            52 => AssetOpsException::unreachable($host, 'prazan odgovor'),
            56 => AssetOpsException::unreachable($host, 'veza prekinuta'),
            default => AssetOpsException::unreachable($host, $message),
        };
    }
}
