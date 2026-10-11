<?php
declare(strict_types=1);
namespace App\Service;

use App\Config\Database;
use PDO;

/**
 * A ship's attributes and bonuses from the SDE, formatted for display.
 * Generic type data only: nothing here relates to any pilot.
 */
final class ShipInfoService
{
    /** Attribute categories in display order (in-game show-info order); any others follow. */
    private const CATEGORY_ORDER = [
        'Fitting', 'Structure', 'Armor', 'Shield', 'Capacitor', 'Targeting', 'Speed and Travel',
        'Drones', 'Fighter Attributes', 'Hangars & Bays', 'EW - Resistance', 'Miscellaneous',
    ];

    /** Categories covered elsewhere: raw bonus values (Bonuses tab) and required skills (page header). */
    private const SKIP_CATEGORIES = ['Bonuses', 'Required Skills'];

    /** Published but internal or shown elsewhere. */
    private const SKIP_ATTRIBUTES = ['damage', 'powerLoad', 'cpuLoad', 'metaLevelOld', 'techLevel', 'maxDirectionalScanRange'];

    private const SIZE_CLASSES = [1 => 'Small', 2 => 'Medium', 3 => 'Large', 4 => 'Capital'];

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    /**
     * @return array [['category' => name, 'attributes' => [['name', 'value'], ...]], ...]
     */
    public function getAttributes(int $typeId): array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT a."attributeID" AS attribute_id, a."attributeName" AS attribute_name,
                   a."displayName" AS name, a."unitID" AS unit_id,
                   COALESCE(ta."valueFloat", ta."valueInt") AS value,
                   COALESCE(c."categoryName", '') AS category, u."displayName" AS unit
            FROM evesdeactive."dgmTypeAttributes" ta
            JOIN evesdeactive."dgmAttributeTypes" a ON a."attributeID" = ta."attributeID"
            LEFT JOIN evesdeactive."dgmAttributeCategories" c ON c."categoryID" = a."categoryID"
            LEFT JOIN evesdeactive."eveUnits" u ON u."unitID" = a."unitID"
            WHERE ta."typeID" = ? AND a.published = true AND COALESCE(a."displayName", '') <> ''
            ORDER BY a."attributeID"
        SQL);
        $stmt->execute([$typeId]);
        $rows = $stmt->fetchAll();

        // Type IDs held in attributes (e.g. fighter or charge types) resolve to names
        $typeIds   = array_map(fn($r) => (int) $r['value'], array_filter($rows, fn($r) => $r['unit_id'] === 116));
        $typeNames = $this->typeNames($typeIds);

        $groups = [];
        $seen   = [];
        foreach ($rows as $row) {
            $category = $row['category'];
            if ($category === '' || in_array($category, self::SKIP_CATEGORIES, true)) continue;
            if (in_array($row['attribute_name'], self::SKIP_ATTRIBUTES, true)) continue;
            // Unused sensor types are stored as 0
            if (preg_match('/^scan\w+Strength$/', $row['attribute_name']) && (float) $row['value'] == 0) continue;
            // Legacy duplicates share a display name (hull*DamageResonance): keep the lowest attribute ID
            if (isset($seen[$category][$row['name']])) continue;
            $seen[$category][$row['name']] = true;

            $groups[$category][] = [
                'name'  => $row['name'],
                'value' => $this->formatValue((float) $row['value'], $row['unit_id'], $row['unit'], $typeNames),
            ];
        }

        // Mass, volume and cargo live on the type itself
        $stmt = $this->db->prepare('SELECT mass, volume, capacity FROM evesdeactive."invTypes" WHERE "typeID" = ?');
        $stmt->execute([$typeId]);
        if ($type = $stmt->fetch()) {
            $groups['Structure'] = array_merge($groups['Structure'] ?? [], [
                ['name' => 'Mass',           'value' => $this->number((float) $type['mass']) . ' kg'],
                ['name' => 'Volume',         'value' => $this->number((float) $type['volume']) . ' m3'],
                ['name' => 'Cargo Capacity', 'value' => $this->number((float) $type['capacity']) . ' m3'],
            ]);
        }

        $rank = array_flip(self::CATEGORY_ORDER);
        uksort($groups, fn($a, $b) => [$rank[$a] ?? PHP_INT_MAX, $a] <=> [$rank[$b] ?? PHP_INT_MAX, $b]);

        $result = [];
        foreach ($groups as $category => $attributes) {
            $result[] = ['category' => $category, 'attributes' => $attributes];
        }
        return $result;
    }

    /**
     * Bonuses grouped as in-game: one group per skill (per level of that skill), then role bonuses,
     * then misc bonuses (skillID -2, e.g. tactical destroyer modes). `importance` is the display
     * order; traitID follows the SDE file order, which isn't.
     *
     * @return array [['title' => …, 'bonuses' => [['amount' => '5%'|null, 'text' => …], ...]], ...]
     */
    public function getBonuses(int $typeId): array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT tr."skillID" AS skill_id, tr.bonus, tr."bonusText" AS text,
                   u."displayName" AS unit, k."typeName" AS skill_name
            FROM evesdeactive."invTraits" tr
            LEFT JOIN evesdeactive."eveUnits" u ON u."unitID" = tr."unitID"
            LEFT JOIN evesdeactive."invTypes" k ON k."typeID" = tr."skillID"
            WHERE tr."typeID" = ?
            ORDER BY tr."skillID" < 0, tr."skillID" = -2, tr."skillID", tr.importance, tr."traitID"
        SQL);
        $stmt->execute([$typeId]);

        $groups = [];
        foreach ($stmt->fetchAll() as $row) {
            $title = match (true) {
                $row['skill_id'] > 0  => ($row['skill_name'] ?? "Skill #{$row['skill_id']}") . ' bonuses (per skill level)',
                $row['skill_id'] == -2 => 'Other bonuses',
                default               => 'Role bonuses',
            };
            $groups[$title][] = [
                'amount' => $row['bonus'] !== null ? $this->formatBonus((float) $row['bonus'], $row['unit']) : null,
                // In-game "showinfo" links become plain text
                'text'   => trim(html_entity_decode(strip_tags($row['text'] ?? ''))),
            ];
        }

        $result = [];
        foreach ($groups as $title => $bonuses) {
            $result[] = ['title' => $title, 'bonuses' => $bonuses];
        }
        return $result;
    }

    /**
     * The ship's in-game description as plain text: <br> becomes a line break, and showinfo links,
     * <url=…>, <i> and <b> markup are dropped. Paragraphs are separated by blank lines.
     */
    public function getDescription(int $typeId): ?string
    {
        $stmt = $this->db->prepare('SELECT description FROM evesdeactive."invTypes" WHERE "typeID" = ?');
        $stmt->execute([$typeId]);
        $text = preg_replace('/<br\s*\/?>/i', "\n", (string) $stmt->fetchColumn());
        $text = trim(html_entity_decode(strip_tags((string) $text)));
        return $text === '' ? null : $text;
    }

    /**
     * Expert Systems that let a pilot fly this ship without the skills. Retired and unpublished ones
     * are left out; hidden ones are handed out at events rather than sold, so they're flagged.
     *
     * @return array [['type_id', 'name', 'days', 'event_only' => bool], ...]
     */
    public function getExpertSystems(int $typeId): array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT t."typeID" AS type_id, t."typeName" AS name, e."durationDays" AS days, e.hidden AS event_only
            FROM evesdeactive."expertSystemShipTypes" s
            JOIN evesdeactive."expertSystems" e ON e."typeID" = s."typeID"
            JOIN evesdeactive."invTypes" t ON t."typeID" = e."typeID"
            WHERE s."shipTypeID" = ? AND t.published = true AND e.retired = false
            ORDER BY e.hidden, t."typeName"
        SQL);
        $stmt->execute([$typeId]);
        return $stmt->fetchAll();
    }

    /**
     * How the ship is built: its blueprint's manufacturing job (materials at ME 0, skills, base time
     * in seconds) and, for T2/T3 hulls, the invention or reverse-engineering jobs that produce that
     * blueprint. Null when no blueprint makes it.
     *
     * @return array|null ['blueprint_id', 'blueprint_name', 'time', 'materials', 'skills',
     *                     'invention' => [['source_id', 'source_name', 'time', 'probability', 'runs',
     *                     'materials', 'skills'], ...]]
     */
    public function getManufacturing(int $typeId): ?array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT p."typeID" AS blueprint_id, b."typeName" AS blueprint_name, a.time
            FROM evesdeactive."industryActivityProducts" p
            JOIN evesdeactive."invTypes" b ON b."typeID" = p."typeID"
            LEFT JOIN evesdeactive."industryActivity" a ON a."typeID" = p."typeID" AND a."activityID" = 1
            WHERE p."productTypeID" = ? AND p."activityID" = 1
            ORDER BY b.published DESC, p."typeID"
            LIMIT 1
        SQL);
        $stmt->execute([$typeId]);
        $build = $stmt->fetch();
        if ($build === false) {
            return null;
        }
        $build += $this->activityInputs((int) $build['blueprint_id'], 1);

        // Activity 8: invention from a T1 blueprint, or reverse engineering from relics (T3)
        $stmt = $this->db->prepare(<<<SQL
            SELECT p."typeID" AS source_id, s."typeName" AS source_name, a.time,
                   pr.probability, p.quantity AS runs
            FROM evesdeactive."industryActivityProducts" p
            JOIN evesdeactive."invTypes" s ON s."typeID" = p."typeID"
            LEFT JOIN evesdeactive."industryActivity" a ON a."typeID" = p."typeID" AND a."activityID" = 8
            LEFT JOIN evesdeactive."industryActivityProbabilities" pr
                   ON pr."typeID" = p."typeID" AND pr."activityID" = 8 AND pr."productTypeID" = p."productTypeID"
            WHERE p."productTypeID" = ? AND p."activityID" = 8
            ORDER BY pr.probability DESC NULLS LAST, s."typeName"
        SQL);
        $stmt->execute([$build['blueprint_id']]);
        $build['invention'] = [];
        foreach ($stmt->fetchAll() as $source) {
            $source['probability'] = $source['probability'] !== null ? (float) $source['probability'] : null;
            $build['invention'][] = $source + $this->activityInputs((int) $source['source_id'], 8);
        }
        return $build;
    }

    /**
     * SKINs for this ship that exist on Tranquility (Serenity-only ones are left out), with the
     * licence lengths each is sold in. Licence names drop the trailing "SKIN (30 Days)".
     *
     * @return array [['name', 'durations' => [-1 (permanent) | days, ...]], ...]
     */
    public function getSkins(int $typeId): array
    {
        // SDE copies loaded before the converter kept visibleTranquility don't have the column:
        // fall back to the internal name, which marks most (not all) China-only skins
        $hasVisibility = (bool) $this->db->query(<<<SQL
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = 'evesdeactive' AND table_name = 'skins' AND column_name = 'visibleTranquility'
        SQL)->fetchColumn();
        $onTranquility = $hasVisibility
            ? 's."visibleTranquility" = true'
            : "s.\"internalName\" NOT LIKE '%Serenity Only%'";

        $stmt = $this->db->prepare(<<<SQL
            SELECT s."skinID" AS skin_id, t."typeName" AS license_name, l.duration
            FROM evesdeactive."skinShip" ss
            JOIN evesdeactive.skins s ON s."skinID" = ss."skinID"
            JOIN evesdeactive."skinLicense" l ON l."skinID" = s."skinID"
            JOIN evesdeactive."invTypes" t ON t."typeID" = l."licenseTypeID"
            WHERE ss."typeID" = ? AND {$onTranquility} AND t.published = true
            ORDER BY s."skinID", l.duration
        SQL);
        $stmt->execute([$typeId]);

        $names = $durations = [];
        foreach ($stmt->fetchAll() as $row) {
            $id = $row['skin_id'];
            $duration = (int) $row['duration'];
            // The permanent licence's name is the cleanest; otherwise strip the duration suffix
            if (!isset($names[$id]) || $duration === -1) {
                $names[$id] = (string) preg_replace('/\s+SKIN(\s*\(.*\))?$/i', '', $row['license_name']);
            }
            $durations[$id][$duration] = true;
        }

        $skins = [];
        foreach ($names as $id => $name) {
            $list = array_keys($durations[$id]);
            // Permanent first, then shortest to longest
            usort($list, fn(int $a, int $b) => ($a === -1 ? PHP_INT_MIN : $a) <=> ($b === -1 ? PHP_INT_MIN : $b));
            $skins[] = ['name' => $name, 'durations' => $list];
        }
        usort($skins, fn(array $a, array $b) => strcasecmp($a['name'], $b['name']));
        return $skins;
    }

    /** Materials (by typeID, so minerals come in their usual order) and skills for one industry activity. */
    private function activityInputs(int $blueprintId, int $activityId): array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT m."materialTypeID" AS type_id, t."typeName" AS name, m.quantity
            FROM evesdeactive."industryActivityMaterials" m
            JOIN evesdeactive."invTypes" t ON t."typeID" = m."materialTypeID"
            WHERE m."typeID" = ? AND m."activityID" = ?
            ORDER BY m."materialTypeID"
        SQL);
        $stmt->execute([$blueprintId, $activityId]);
        $materials = $stmt->fetchAll();

        $stmt = $this->db->prepare(<<<SQL
            SELECT s."skillID" AS skill_id, t."typeName" AS name, s.level
            FROM evesdeactive."industryActivitySkills" s
            JOIN evesdeactive."invTypes" t ON t."typeID" = s."skillID"
            WHERE s."typeID" = ? AND s."activityID" = ?
            ORDER BY t."typeName"
        SQL);
        $stmt->execute([$blueprintId, $activityId]);
        return ['materials' => $materials, 'skills' => $stmt->fetchAll()];
    }

    private function formatValue(float $value, ?int $unitId, ?string $unit, array $typeNames): string
    {
        return match ($unitId) {
            101     => $this->number($value / 1000) . ' s',          // milliseconds
            108     => $this->number((1 - $value) * 100) . '%',      // resonance: 0.75 = 25% resist
            127     => $this->number($value * 100) . '%',            // absolute percent: 0.25 = 25%
            105     => $this->number($value) . '%',
            116     => $typeNames[(int) $value] ?? "Type #{$this->number($value)}",
            117     => self::SIZE_CLASSES[(int) $value] ?? $this->number($value),
            137     => $value ? 'Yes' : 'No',
            139     => '+' . $this->number($value),
            122     => $this->number($value),                         // fitting slots: no unit
            104     => $this->number($value) . 'x',                   // multiplier
            default => trim($this->number($value) . ' ' . ($unit ?? '')),
        };
    }

    private function formatBonus(float $bonus, ?string $unit): string
    {
        return match ($unit) {
            '%'     => $this->number($bonus) . '%',
            '+'     => '+' . $this->number($bonus),
            'x'     => $this->number($bonus) . 'x',
            null    => $this->number($bonus),
            default => $this->number($bonus) . ' ' . $unit,
        };
    }

    /** Thousands separators, up to two decimals, no trailing zeros. */
    private function number(float $value): string
    {
        $formatted = number_format($value, 2);
        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }

    /** @return array [typeID => typeName] */
    private function typeNames(array $typeIds): array
    {
        $typeIds = array_values(array_unique($typeIds));
        if ($typeIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($typeIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT \"typeID\", \"typeName\" FROM evesdeactive.\"invTypes\" WHERE \"typeID\" IN ({$placeholders})"
        );
        $stmt->execute($typeIds);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}
