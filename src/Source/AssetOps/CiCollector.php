<?php
// src/Source/AssetOps/CiCollector.php
//
// Cita zatecene CI-jeve iz AssetOPS-a: sve virtuelne masine i svu fizicku
// opremu.
//
// Zasto ceo entitet a ne samo mapirani tip: filter po tipu radi, ali ne
// stedi ni jedan poziv (ceo entitet je jedna strana), a filtrirano citanje
// ne bi videlo CI kome je neko promenio tip — pa bi SyncOPS napravio
// duplikat pored njega. Procitani su svi, pa takav CI moze da se prijavi.
// Duze obrazlozenje stoji u migraciji 0016.
//
// Cita se LISTA, ne detalji po zapisu. Lista nosi i attribute_values, pa je
// dovoljna i za spajanje i za prikaz; 36 CI-jeva je jedan poziv, a ne 36.
//
// Jedan izuzetak: description. Njega lista NE vraca, nego samo ruta
// pojedinacnog zapisa. Zato se za odabrane CI-jeve dodaje jos jedan poziv —
// ali se iz tog odgovora uzima SAMO description.
//
// Zasto samo to polje: kod entiteta "asset" detalji nisu nadskup liste.
// Lista daje pun objekat rack-a a detalji plici, dok je sa status i tags
// obrnuto. Ko odatle prepise ceo zapis, tiho osiromasi payload.
//
// Koje CI-jeve dopuniti odlucuje pozivalac, ne ovaj razred. Smisao je da to
// budu samo spojeni CI-jevi kod kojih je opis mapiran politikom kojoj
// zatecena vrednost stvarno treba:
//
//   ne diraj         polje se ni ne racuna
//   pri kreiranju    Planner preskace pre citanja zatecenog
//   ako je prazno    TREBA — od zatecene vrednosti zavisi da li se upisuje
//   prepisuj uvek    TREBA — bez nje bi svaki prolaz video izmenu i upisivao
//                    isti opis u nedogled
//
// Prazan spisak znaci nijedan dodatni poziv.

declare(strict_types=1);

namespace SyncOps\Source\AssetOps;

use DateTimeImmutable;
use Throwable;

final class CiCollector
{
    /** Entitet => ruta. Entitet je i ono cime se EAV atribut vezuje. */
    public const ROUTES = [
        'virtual-asset' => '/api/virtual-assets/',
        'asset'         => '/api/assets/',
    ];

    /** Odakle se cita tip CI-ja, po entitetu. */
    private const TYPE_FIELD = [
        'virtual-asset' => 'virtual_asset_type',
        'asset'         => 'asset_type',
    ];

    /**
     * Koliko CI-jeva je ostalo bez opisa jer dodatni poziv nije prosao.
     *
     * Broji se da bi se moglo prijaviti. Prazan opis koji je posledica
     * neuspelog poziva izgleda isto kao opis kog u AssetOPS-u nema, a to
     * dvoje ne sme da se mesa: na osnovu prvog bi se pomislilo da nema sta
     * da se povuce.
     */
    private int $descriptionFailures = 0;

    /**
     * Svi CI-jevi oba entiteta, spremni za upis.
     *
     * $correlationRefs je, po entitetu, polje koje mapiranje oznacava kao
     * kljuc spajanja, u obliku "eav:<slug>" ili "native:<polje>". Kljuc ne
     * mora da bude EAV atribut: kod masina je instance_uuid i mora u atribut
     * jer nativnog polja za njega nema, ali kod hostova serijski broj jeste
     * nativno polje.
     *
     * Entitet bez podesenog kljuca se svejedno cita — bez vrednosti kljuca,
     * ali se vidi koliko ga CI-jeva ima i kako izgledaju.
     *
     * $describeUuids je, po entitetu, skup uuid-ova kojima treba dopuniti
     * description iz rute pojedinacnog zapisa — u obliku mape
     * uuid => true, da provera bude jedno isset a ne pretraga niza.
     *
     * @param array<string, ?string> $correlationRefs entitet => "eav:slug" ili "native:polje"
     * @param array<string, array<string, true>> $describeUuids entitet => {uuid: true}
     * @return array<string, list<array<string, mixed>>> entitet => redovi
     */
    public function collect(
        AssetOpsClient $client,
        array $correlationRefs = [],
        array $describeUuids = [],
    ): array {
        $this->descriptionFailures = 0;

        $result = [];

        foreach (self::ROUTES as $entity => $path) {
            $rows   = [];
            $wanted = $describeUuids[$entity] ?? [];

            foreach ($client->listAll($path) as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $uuid = isset($item['uuid']) ? trim((string) $item['uuid']) : '';

                // Dopuna ide PRE row(), jer row() vec tu pravi payload —
                // description mora da bude u njemu. Planner zatecenu
                // vrednost nativnog polja cita iz payload-a, ne iz kolone.
                if ($uuid !== '' && isset($wanted[$uuid])) {
                    $item = $this->withDescription($client, $path, $uuid, $item);
                }

                $row = $this->row($item, $entity, $correlationRefs[$entity] ?? null);

                if ($row !== null) {
                    $rows[] = $row;
                }
            }

            $result[$entity] = $rows;
        }

        return $result;
    }

    /**
     * Koliko je dopuna opisa palo u poslednjem prolazu.
     */
    public function descriptionFailures(): int
    {
        return $this->descriptionFailures;
    }

    /**
     * Zapis iz liste, dopunjen opisom iz rute pojedinacnog zapisa.
     *
     * Uzima se samo description. Ostatak detalja se namerno odbacuje: kod
     * entiteta "asset" detalji su za neka polja plici od liste, pa bi
     * spajanje celih odgovora osiromasilo payload.
     *
     * Pad jednog poziva ne obara prolaz — ostalih 40 CI-jeva je procitano i
     * vredi upisati. Takav zapis ostaje kakav je lista dala, a broj neuspeha
     * se prijavljuje da se prazan opis ne pripise AssetOPS-u.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function withDescription(
        AssetOpsClient $client,
        string $path,
        string $uuid,
        array $item,
    ): array {
        try {
            $detail = $client->get($path . $uuid . '/');
        } catch (Throwable $e) {
            ++$this->descriptionFailures;
            error_log("SyncOPS opis za {$uuid}: " . $e->getMessage());

            return $item;
        }

        // Ruta koja polje ne vrati nije greska: tada ga za taj entitet nema,
        // i to je odgovor. Razlikuje se od null vrednosti, koja znaci da je
        // polje tamo i da je prazno.
        if (!array_key_exists('description', $detail)) {
            return $item;
        }

        $item['description'] = $detail['description'];

        return $item;
    }

    /**
     * Jedan CI. Bez uuid-a nema identiteta, pa takav zapis otpada.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    private function row(array $item, string $entity, ?string $correlationRef): ?array
    {
        $uuid = isset($item['uuid']) ? trim((string) $item['uuid']) : '';

        if ($uuid === '') {
            return null;
        }

        $value = $this->correlation($item, $correlationRef);

        return [
            'uuid'              => $uuid,
            'name'              => $this->name($item['name'] ?? ''),
            'description'       => $this->text($item['description'] ?? null),
            'type_slug'         => $this->slug($item[self::TYPE_FIELD[$entity]] ?? null),
            'status_slug'       => $this->slug($item['status'] ?? null),
            'location_slug'     => $this->slug($item['location'] ?? null),
            'serial_number'     => $this->text($item['serial_number'] ?? null),
            // Kljuc se pamti i kada vrednosti nema: tako se u prikazu vidi
            // razlika izmedju „kljuc nije podesen" i „podesen je, ali je
            // ovaj CI prazan".
            'correlation_key'   => $correlationRef,
            'correlation_value' => $value,
            'remote_updated_at' => $this->utc($item['updated_at'] ?? null),
            'payload'           => (string) json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * Vrednost kljuca spajanja, iz EAV atributa ili iz nativnog polja.
     *
     * Veza (relation) se ne prihvata kao kljuc: slug lokacije ili grupe nije
     * identitet objekta nego njegova osobina, i deli ga vise CI-jeva.
     *
     * @param array<string, mixed> $item
     */
    private function correlation(array $item, ?string $ref): ?string
    {
        if ($ref === null || $ref === '') {
            return null;
        }

        [$kind, $key] = array_pad(explode(':', $ref, 2), 2, '');

        if ($key === '') {
            return null;
        }

        return match ($kind) {
            'eav'    => $this->attributeValue($item, $key),
            'native' => $this->text($item[$key] ?? null),
            default  => null,
        };
    }

    /**
     * Vrednost jednog EAV atributa.
     *
     * attribute_values je NIZ redova, a slug je unutra pod "attribute" —
     * nije mapa {slug: vrednost}, iako se pri upisu salje bas tako.
     *
     * Red koji postoji nije isto sto i red koji ima vrednost: vecina redova
     * na stvarnoj instanci ima value: null. Zato se prazno izjednacava sa
     * nepostojecim — kljuc bez vrednosti nije kljuc.
     *
     * Niz kao vrednost (multiple_select) se ne prihvata: kljuc spajanja mora
     * da bude jedna vrednost koja se moze uporediti.
     *
     * @param array<string, mixed> $item
     */
    private function attributeValue(array $item, string $slug): ?string
    {
        foreach ((array) ($item['attribute_values'] ?? []) as $row) {
            if (!is_array($row) || (string) ($row['attribute']['slug'] ?? '') !== $slug) {
                continue;
            }

            $value = $row['value'] ?? null;

            if ($value === null || is_array($value) || is_bool($value)) {
                return null;
            }

            $text = trim((string) $value);

            return $text === '' ? null : $text;
        }

        return null;
    }

    /**
     * Slug ugradjene veze. Status sme da bude null, location je objekat,
     * a kod tipa zavisi od entiteta — svi se citaju isto, kroz "slug".
     */
    private function slug(mixed $value): ?string
    {
        if (!is_array($value)) {
            return null;
        }

        $slug = isset($value['slug']) ? trim((string) $value['slug']) : '';

        return $slug === '' ? null : $slug;
    }

    /**
     * Ime CI-ja je string. Kod lokacija ume da bude niz (putanja hijerarhije),
     * pa se i ovde niz prihvata — jedna izmena na njihovoj strani ne treba da
     * obori prikupljanje.
     */
    private function name(mixed $value): string
    {
        if (is_array($value)) {
            $last = end($value);

            return $last === false ? '' : (string) $last;
        }

        return (string) $value;
    }

    private function text(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_bool($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * ISO 8601 sa mikrosekundama u oblik koji MySQL DATETIME prima.
     *
     * Mikrosekunde se odbacuju jer ih kolona ne cuva. Da se cuvaju u polju
     * koje ih ne nosi, poredjenje "da li se promenilo" bi uvek bilo tacno.
     */
    private function utc(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone(
                new \DateTimeZone('UTC')
            )->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }
}
