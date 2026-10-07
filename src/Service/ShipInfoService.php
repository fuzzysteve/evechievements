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
            FROM evesde."dgmTypeAttributes" ta
            JOIN evesde."dgmAttributeTypes" a ON a."attributeID" = ta."attributeID"
            LEFT JOIN evesde."dgmAttributeCategories" c ON c."categoryID" = a."categoryID"
            LEFT JOIN evesde."eveUnits" u ON u."unitID" = a."unitID"
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
        $stmt = $this->db->prepare('SELECT mass, volume, capacity FROM evesde."invTypes" WHERE "typeID" = ?');
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
     * Bonuses grouped as in-game: one group per skill (per level of that skill), then role bonuses.
     *
     * @return array [['title' => …, 'bonuses' => [['amount' => '5%'|null, 'text' => …], ...]], ...]
     */
    public function getBonuses(int $typeId): array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT tr."skillID" AS skill_id, tr.bonus, tr."bonusText" AS text,
                   u."displayName" AS unit, k."typeName" AS skill_name
            FROM evesde."invTraits" tr
            LEFT JOIN evesde."eveUnits" u ON u."unitID" = tr."unitID"
            LEFT JOIN evesde."invTypes" k ON k."typeID" = tr."skillID"
            WHERE tr."typeID" = ?
            ORDER BY tr."skillID" < 0, tr."skillID", tr."traitID"
        SQL);
        $stmt->execute([$typeId]);

        $groups = [];
        foreach ($stmt->fetchAll() as $row) {
            $title = $row['skill_id'] > 0
                ? ($row['skill_name'] ?? "Skill #{$row['skill_id']}") . ' bonuses (per skill level)'
                : 'Role bonuses';
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
            "SELECT \"typeID\", \"typeName\" FROM evesde.\"invTypes\" WHERE \"typeID\" IN ({$placeholders})"
        );
        $stmt->execute($typeIds);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}
