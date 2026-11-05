<?php
// src/Storage/LinkRepository.php
//
// Veze izmedju vCenter objekata i CI-jeva u AssetOPS-u.
//
// Odvojeno od AssetOpsRepository jer ovo nije podatak procitan iz AssetOPS-a
// nego nasa odluka o tome sta je sta. Referentni spiskovi i CI-jevi se
// osvezavaju i mogu da se bace; veze ne — one su jedino sto SyncOPS zna a
// ne moze ponovo da sazna.
//
// Upis ide kroz jedinstvene indekse tabele, ne kroz proveru u kodu: dve
// masine ne mogu da uzmu isti CI, i to drzi baza. Red koji padne na indeksu
// se preskace i prijavljuje, umesto da obori ceo posao — spajanje od sto
// veza ne sme da propadne zbog jedne sporne.

declare(strict_types=1);

namespace SyncOps\Storage;

use PDO;
use PDOException;

final class LinkRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Postojece veze jedne vrste izvora, po source_id.
     *
     * @return array<int, array<string, mixed>>
     */
    public function bySourceKind(int $integrationId, string $sourceKind): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM cmdb_link
              WHERE integration_id = :i AND source_kind = :k'
        );
        $stmt->execute(['i' => $integrationId, 'k' => $sourceKind]);

        $byId = [];

        foreach ($stmt->fetchAll() as $row) {
            $byId[(int) $row['source_id']] = $row;
        }

        return $byId;
    }

    /**
     * Napravi veze. Vraca koliko ih je napravljeno i sta je preskoceno.
     *
     * Preskace se red koji bi prekrsio jedinstvenost — objekat koji je u
     * medjuvremenu vec spojen, ili CI koji je neko drugi uzeo. To nije
     * greska nego ishod koji treba prikazati.
     *
     * @param list<array{source_id: int, ci_uuid: string, matched_by: string, confirmed: bool}> $links
     * @return array{created: int, skipped: list<array{source_id: int, ci_uuid: string}>}
     */
    public function create(int $integrationId, string $sourceKind, array $links): array
    {
        $sql = 'INSERT INTO cmdb_link
                    (integration_id, source_kind, source_id, ci_uuid,
                     matched_by, confirmed, linked_at)
                 VALUES
                    (:i, :k, :source, :uuid, :matched, :confirmed, UTC_TIMESTAMP())';

        $stmt    = $this->pdo->prepare($sql);
        $created = 0;
        $skipped = [];

        foreach ($links as $link) {
            try {
                $stmt->execute([
                    'i'         => $integrationId,
                    'k'         => $sourceKind,
                    'source'    => $link['source_id'],
                    'uuid'      => $link['ci_uuid'],
                    'matched'   => $link['matched_by'],
                    'confirmed' => $link['confirmed'] ? 1 : 0,
                ]);

                $created++;
            } catch (PDOException $e) {
                // 23000 je prekrsena jedinstvenost. Sve ostalo je prava
                // greska i ne sme da se progura kao "preskoceno".
                if ($e->getCode() !== '23000') {
                    throw $e;
                }

                $skipped[] = ['source_id' => $link['source_id'], 'ci_uuid' => $link['ci_uuid']];

                // Iskaz se posle pale izvrsavanja ne koristi ponovo nego se
                // sprema iznova. Nije stvar urednosti: drajver ume da ostavi
                // iskaz u stanju u kom sledeci execute() padne sa "bad
                // parameter or other API misuse", pa bi jedna sporna veza
                // oborila i sve ispravne iza sebe.
                $stmt = $this->pdo->prepare($sql);
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Raskini vezu za jedan objekat.
     *
     * Veza se raskida samo rucno. Ni nestanak CI-ja ni nestanak masine je ne
     * brisu sami: odluka sta sa objektom koji je nestao pripada fazi
     * nestanka, a ne spajanju.
     */
    public function remove(int $integrationId, string $sourceKind, int $sourceId): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM cmdb_link
              WHERE integration_id = :i AND source_kind = :k AND source_id = :s'
        );
        $stmt->execute(['i' => $integrationId, 'k' => $sourceKind, 's' => $sourceId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Zapamti sta je poslednji put poslato za jedan objekat.
     *
     * Cuva se celo telo, ne otisak. Hes odgovara na pitanje „da li se nesto
     * promenilo", a pitanje koje se zaista postavlja je po polju: da li je
     * covek rucno ispravio bas ovo polje posle naseg upisa. Hes se cuva uz
     * njega, za brzo poredjenje „isto kao prosli put".
     *
     * @param array<string, mixed> $payload
     */
    public function recordPush(int $integrationId, string $sourceKind, int $sourceId, array $payload): void
    {
        $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmt = $this->pdo->prepare(
            'UPDATE cmdb_link
                SET pushed_hash    = :hash,
                    pushed_payload = :body,
                    pushed_at      = UTC_TIMESTAMP()
              WHERE integration_id = :i AND source_kind = :k AND source_id = :s'
        );

        $stmt->execute([
            'hash' => hash('sha256', $body),
            'body' => $body,
            'i'    => $integrationId,
            'k'    => $sourceKind,
            's'    => $sourceId,
        ]);
    }

    /**
     * Koliko veza postoji, po vrsti izvora i jacini.
     *
     * @return array<string, array{total: int, confirmed: int}>
     */
    public function counts(int $integrationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT source_kind, COUNT(*) AS total, SUM(confirmed) AS confirmed
               FROM cmdb_link
              WHERE integration_id = :i
              GROUP BY source_kind'
        );
        $stmt->execute(['i' => $integrationId]);

        $byKind = [];

        foreach ($stmt->fetchAll() as $row) {
            $byKind[(string) $row['source_kind']] = [
                'total'     => (int) $row['total'],
                'confirmed' => (int) $row['confirmed'],
            ];
        }

        return $byKind;
    }
}
