<?php
// src/Source/VCenter/Collector/LicenseCollector.php
//
// Prikuplja licence sa vCentera. Jedan poziv za sve.
//
// Zasto se razlikuje od ostalih kolektora: licence ne stoje u stablu
// inventara. Masine, hostovi i klasteri se obilaze kroz ContainerView jer ih
// ima mnogo; licence drzi LicenseManager, jedan jedini objekat po vCenteru,
// pa se cita direktno.
//
// NEDOSTATAK PRAVA SE PRIJAVLJUJE, NE PRECUTKUJE. Citanje licenci trazi pravo
// Global.Licenses, koje namenski read-only nalog po pravilu nema. vCenter u
// tom slucaju ne vraca gresku nego uspesan odgovor sa praznim mestom i
// objasnjenjem (missingSet). Kolektor napisan kao ostali video bi nula licenci
// i run bi prosao kao uspesan, a tabela bi ostala prazna bez ikakvog traga
// zasto. Zato se to podize kao upozorenje koje zavrsi u napomenama run-a i u
// GUI-ju.

declare(strict_types=1);

namespace SyncOps\Source\VCenter\Collector;

use SyncOps\Source\Dto\LicenseDto;
use SyncOps\Source\VCenter\Client\ClientInterface;
use SyncOps\Source\VCenter\Mapper\LicenseMapper;

final class LicenseCollector
{
    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        private readonly LicenseMapper $mapper = new LicenseMapper(),
    ) {
    }

    /**
     * Sto je izostalo a ne obara run.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return list<LicenseDto>
     */
    public function collect(ClientInterface $client): array
    {
        $this->warnings = [];

        $result  = $client->listLicenses();
        $missing = $result['missingPrivilege'] ?? null;

        if ($missing !== null) {
            $this->warnings[] = sprintf(
                'licence nisu procitane: nalogu fali pravo %s na vCenteru. '
                . 'Dodeli ga na korenu, sa propagacijom, pa pokreni prikupljanje ponovo.',
                $missing
            );

            return [];
        }

        $licenses = [];

        foreach ($result['licenses'] ?? [] as $row) {
            // Bez kljuca nema identiteta, pa nema ni reda u bazi.
            if ((string) ($row['key'] ?? '') === '') {
                continue;
            }

            $licenses[] = $this->mapper->map($row);
        }

        return $licenses;
    }
}
