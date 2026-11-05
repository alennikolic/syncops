<?php
// src/Source/AssetOps/ReferenceCollector.php
//
// Cita referentne spiskove iz AssetOPS-a: lokacije, statuse, visibility
// grupe i oba spiska tipova.
//
// Zasto bas ovih pet: to su vrednosti bez kojih se CI ne moze ni upisati ni
// razumeti. Pri kreiranju se veze salju kao SLUGOVI, pa SyncOPS mora unapred
// da zna koji slugovi postoje. Slug se pritom ne izvodi iz imena — "VM" ima
// slug "kub", "Beograd" ima "beograd-3" — pa pogadjanje ne dolazi u obzir.
//
// Tipovi su dva odvojena spiska i to nije previd: virtuelna masina bira iz
// /api/virtual-asset-types/, fizicka oprema iz /api/asset-types/. Dva
// razlicita vokabulara za dve razlicite vrste CI-ja.
//
// Lokacije i tipovi stizu kao STABLO. Spljostavaju se ovde, uz pamcenje
// dubine i redosleda obilaska, da bi prikaz mogao da ih pokaze onako kako
// izgledaju u AssetOPS-u bez ponovnog sklapanja stabla.

declare(strict_types=1);

namespace SyncOps\Source\AssetOps;

final class ReferenceCollector
{
    /** Vrsta => ruta. Redosled je i redosled prikaza na stranici. */
    public const SOURCES = [
        'location'           => '/api/locations/',
        'status'             => '/api/statuses/',
        'visibility_group'   => '/api/visibility-groups/',
        'virtual_asset_type' => '/api/virtual-asset-types/',
        'asset_type'         => '/api/asset-types/',
        // Definicije EAV atributa. Citaju se odavde a ne iz eav_fields u
        // odgovoru liste: eav_fields izostavlja polja koja API vraca, a kod
        // virtual-assets ume da bude prazan iako atributi za taj entitet
        // postoje. Ovo je jedini pouzdan spisak.
        'eav_attribute'      => '/api/eav/attributes/',
    ];

    /** Vrste koje stizu kao stablo i moraju da se spljoste. */
    private const HIERARCHICAL = ['location', 'virtual_asset_type', 'asset_type'];

    /**
     * Svi referentni zapisi, spremni za upis.
     *
     * @return array<string, list<array<string, mixed>>> vrsta => redovi
     */
    public function collect(AssetOpsClient $client): array
    {
        $result = [];

        foreach (self::SOURCES as $kind => $path) {
            $items = $client->listAll($path);

            $result[$kind] = in_array($kind, self::HIERARCHICAL, true)
                ? $this->flatten($items)
                : $this->flat($items);
        }

        return $result;
    }

    /**
     * Ravan spisak: statusi i visibility grupe nemaju hijerarhiju.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function flat(array $items): array
    {
        $rows     = [];
        $position = 0;

        foreach ($items as $item) {
            $row = $this->row($item, null, 0, $position++);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Stablo u ravan spisak, dubinski, uz cuvanje redosleda.
     *
     * Dubinski a ne po nivoima: tako dete stoji odmah ispod svog roditelja,
     * sto je redosled u kom se stablo i cita.
     *
     * Zasto se ne oslanjamo na total_items: kod stabla on broji samo korene.
     * Na testnoj instanci javlja 5, a lokacija je 15. Broj se zato dobija
     * obilaskom, nikako iz odgovora.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function flatten(array $items): array
    {
        $rows     = [];
        $position = 0;

        $walk = function (array $nodes, ?string $parentSlug, int $depth) use (&$walk, &$rows, &$position): void {
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }

                $row = $this->row($node, $parentSlug, $depth, $position++);

                if ($row === null) {
                    continue;
                }

                $rows[] = $row;

                $children = $node['children'] ?? [];

                if (is_array($children) && $children !== []) {
                    $walk($children, (string) $node['slug'], $depth + 1);
                }
            }
        };

        $walk($items, null, 0);

        return $rows;
    }

    /**
     * Jedan zapis. Bez sluga nema identiteta, pa takav red otpada.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    private function row(array $item, ?string $parentSlug, int $depth, int $position): ?array
    {
        $slug = isset($item['slug']) ? (string) $item['slug'] : '';

        if ($slug === '') {
            return null;
        }

        return [
            'slug'        => $slug,
            // Ime je kod lokacija string na njihovoj ruti, ali NIZ kada je
            // lokacija ugradjena u zapis CI-ja. Ovde citamo sa njihove rute,
            // pa je string — ali niz se svejedno prihvata, da jedna izmena
            // na njihovoj strani ne obori prikupljanje.
            'name'        => $this->name($item['name'] ?? $slug),
            'parent_slug' => $parentSlug,
            'depth'       => $depth,
            'position'    => $position,
            'color'       => isset($item['color']) ? (string) $item['color'] : null,
            'payload'     => (string) json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    private function name(mixed $value): string
    {
        if (is_array($value)) {
            // Putanja hijerarhije; list je ono sto se prikazuje.
            $last = end($value);

            return $last === false ? '' : (string) $last;
        }

        return (string) $value;
    }
}
