<?php
declare(strict_types=1);
namespace App\Service;

use App\Config\Database;
use PDO;

/** Static skill details from the SDE, for the hover popup. Nothing here relates to any pilot. */
final class SkillService
{
    private const ATTR_PRIMARY   = 180;  // primaryAttribute (value: an attribute ID below)
    private const ATTR_SECONDARY = 181;  // secondaryAttribute
    private const ATTR_RANK      = 275;  // skillTimeConstant, "Training time multiplier"
    private const ATTRIBUTES     = [164 => 'Charisma', 165 => 'Intelligence', 166 => 'Memory', 167 => 'Perception', 168 => 'Willpower'];
    private const CATEGORY_SKILL = 16;

    /**
     * Training estimates assume both attributes at 20 on an Omega clone with no implants.
     * EVE trains primary + secondary / 2 skill points per minute: 20 + 10 = 30.
     */
    public const ESTIMATE_ATTRIBUTE = 20;

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    /**
     * @return array|null ['skill_id', 'name', 'group', 'description', 'rank', 'primary', 'secondary',
     *                     'sp' => [1..5 => int], 'train_minutes' => [1..5 => int], 'train_attribute' => 20,
     *                     'prerequisites' => [['skill_id', 'name', 'level'], ...]]
     */
    public function getSkill(int $skillId): ?array
    {
        // Attribute/category IDs are class constants, written into the SQL directly: PDO's native
        // prepares don't allow one named placeholder to appear more than once.
        $rank = self::ATTR_RANK; $primary = self::ATTR_PRIMARY; $secondary = self::ATTR_SECONDARY;
        $category = self::CATEGORY_SKILL;
        $stmt = $this->db->prepare(<<<SQL
            SELECT t."typeID" AS skill_id, t."typeName" AS name, g."groupName" AS group_name,
                   t.description,
                   MAX(CASE WHEN a."attributeID" = {$rank}      THEN COALESCE(a."valueFloat", a."valueInt") END) AS rank,
                   MAX(CASE WHEN a."attributeID" = {$primary}   THEN COALESCE(a."valueFloat", a."valueInt") END) AS primary_attr,
                   MAX(CASE WHEN a."attributeID" = {$secondary} THEN COALESCE(a."valueFloat", a."valueInt") END) AS secondary_attr
            FROM evesde."invTypes" t
            JOIN evesde."invGroups" g ON g."groupID" = t."groupID"
            LEFT JOIN evesde."dgmTypeAttributes" a ON a."typeID" = t."typeID"
                 AND a."attributeID" IN ({$rank}, {$primary}, {$secondary})
            WHERE t."typeID" = ? AND t.published = true AND g."categoryID" = {$category}
            GROUP BY t."typeID", t."typeName", g."groupName", t.description
        SQL);
        $stmt->execute([$skillId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $prereqs = $this->db->prepare(<<<SQL
            SELECT s."skillID" AS skill_id, k."typeName" AS name, s.level
            FROM evesde."shipSkills" s
            JOIN evesde."invTypes" k ON k."typeID" = s."skillID"
            WHERE s."typeID" = ?
            ORDER BY s."groupID"
        SQL);
        $prereqs->execute([$skillId]);

        $rankValue = (float) ($row['rank'] ?? 1);
        return [
            'skill_id'      => $row['skill_id'],
            'name'          => $row['name'],
            'group'         => $row['group_name'],
            // One published skill has HTML markup in its description; show plain text
            'description'   => trim(html_entity_decode(strip_tags((string) $row['description']))),
            'rank'          => $rankValue,
            'primary'       => self::ATTRIBUTES[(int) $row['primary_attr']] ?? null,
            'secondary'     => self::ATTRIBUTES[(int) $row['secondary_attr']] ?? null,
            'sp'            => self::spPerLevel($rankValue),
            'train_minutes' => self::trainMinutes(self::spPerLevel($rankValue)),
            'train_attribute' => self::ESTIMATE_ATTRIBUTE,
            'prerequisites' => $prereqs->fetchAll(),
        ];
    }

    /**
     * Skill points needed to reach each level: 250 × rank × √32^(level − 1), rounded UP to whole
     * points (CCP's table: 250 / 1,415 / 8,000 / 45,255 / 256,000 at rank 1). The SDE stores only the
     * rank (skillTimeConstant), not the per-level totals.
     *
     * @return array [1..5 => int]
     */
    /**
     * Names and ranks for many skills in one query.
     *
     * @param  int[] $skillIds
     * @return array [skillID => ['name' => string, 'rank' => float]]
     */
    public function details(array $skillIds): array
    {
        $skillIds = array_values(array_unique(array_map('intval', $skillIds)));
        if ($skillIds === []) {
            return [];
        }
        $rank = self::ATTR_RANK;
        $placeholders = implode(',', array_fill(0, count($skillIds), '?'));
        $stmt = $this->db->prepare(<<<SQL
            SELECT t."typeID", t."typeName",
                   COALESCE(a."valueFloat", a."valueInt", 1) AS rank
            FROM evesde."invTypes" t
            LEFT JOIN evesde."dgmTypeAttributes" a ON a."typeID" = t."typeID" AND a."attributeID" = {$rank}
            WHERE t."typeID" IN ({$placeholders})
        SQL);
        $stmt->execute($skillIds);

        $details = [];
        foreach ($stmt->fetchAll() as $row) {
            $details[$row['typeID']] = ['name' => $row['typeName'], 'rank' => (float) $row['rank']];
        }
        return $details;
    }

    /**
     * Prerequisite graph for the given skills and everything they lead to, one query per depth:
     * [skillID => [prerequisiteID => level]] (direct prerequisites per skill; skills with none map to []).
     *
     * @param int[] $skillIds
     */
    public function prerequisiteGraph(array $skillIds): array
    {
        $graph    = [];
        $frontier = array_values(array_unique(array_map('intval', $skillIds)));
        while ($frontier !== []) {
            foreach ($frontier as $id) {
                $graph[$id] = [];
            }
            $placeholders = implode(',', array_fill(0, count($frontier), '?'));
            $stmt = $this->db->prepare(
                "SELECT \"typeID\", \"skillID\", level FROM evesde.\"shipSkills\" WHERE \"typeID\" IN ({$placeholders})"
            );
            $stmt->execute($frontier);

            $next = [];
            foreach ($stmt->fetchAll() as $row) {
                $graph[$row['typeID']][$row['skillID']] = $row['level'];
                if (!isset($graph[$row['skillID']])) {
                    $next[$row['skillID']] = true;
                }
            }
            $frontier = array_keys($next);
        }
        return $graph;
    }

    /** Approximate minutes to train $points skill points at the estimate rate (rounded UP). */
    public static function minutesFor(float $points): int
    {
        return (int) ceil(max(0.0, $points) / (self::ESTIMATE_ATTRIBUTE + self::ESTIMATE_ATTRIBUTE / 2));
    }

    /**
     * Approximate minutes to train each level from the previous one, at ESTIMATE_ATTRIBUTE in both
     * the primary and secondary attribute.
     *
     * @param  array $sp [1..5 => total skill points at that level]
     * @return array     [1..5 => int minutes]
     */
    public static function trainMinutes(array $sp): array
    {
        $perMinute = self::ESTIMATE_ATTRIBUTE + self::ESTIMATE_ATTRIBUTE / 2;
        $minutes   = [];
        foreach ($sp as $level => $total) {
            // Minutes to train this level from the one below, rounded UP to whole minutes
            $minutes[$level] = (int) ceil(($total - ($sp[$level - 1] ?? 0)) / $perMinute);
        }
        return $minutes;
    }

    public static function spPerLevel(float $rank): array
    {
        $sp = [];
        for ($level = 1; $level <= 5; $level++) {
            $sp[$level] = (int) ceil(round(250 * $rank * (32 ** (($level - 1) / 2)), 6));
        }
        return $sp;
    }
}
