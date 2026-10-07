<?php
declare(strict_types=1);
namespace App\Service;

use App\Config\Database;
use PDO;

/**
 * Builds the in-game style ship tree from the SDE.
 *
 * The SDE provides the tree groups, which factions they appear in, the
 * per-faction prerequisite skills and each ship's shipTreeGroupID, but not
 * the layout the client draws. Branches are derived from the prerequisites:
 * a group hangs off the group whose prerequisites it strictly extends
 * (Interceptor needs Frigate V + Interceptors I, so it branches from Frigate).
 * Groups with no direct match look one level deeper, at the level V skills
 * their own prerequisite skills need (Tactical Destroyer → Destroyer V).
 *
 * The remaining root groups (the hulls) are linked the same way, at any level:
 * Destroyer's skill needs Frigate III, so Destroyer follows Frigate. Roots with
 * no displayed prerequisites (Corvette, Shuttle) are the starting points.
 */
final class ShipTreeService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    /** Factions that have a ship tree, empires first. */
    public function getFactions(): array
    {
        return $this->db->query(<<<SQL
            SELECT f."factionID" AS faction_id, c."factionName" AS name,
                   c."corporationID" AS corporation_id, f.description,
                   COUNT(t."typeID") AS ship_count
            FROM evesde."shipTreeFactions" f
            JOIN evesde."chrFactions" c ON c."factionID" = f."factionID"
            JOIN evesde."invTypes" t ON t."factionID" = f."factionID"
            WHERE {$this->treeShipFilter('t')}
            GROUP BY f."factionID", c."factionName", c."corporationID", f.description
            ORDER BY f."factionID"
        SQL)->fetchAll();
    }

    /** Every ship that appears in a ship tree: [factionID => [typeID, ...]] */
    public function getShipIdsByFaction(): array
    {
        $rows = $this->db->query(
            "SELECT t.\"factionID\", t.\"typeID\" FROM evesde.\"invTypes\" t WHERE {$this->treeShipFilter('t')}"
        )->fetchAll();

        $byFaction = [];
        foreach ($rows as $row) {
            $byFaction[$row['factionID']][] = $row['typeID'];
        }
        return $byFaction;
    }

    /**
     * [typeID => masteryLevel] for every ship a pilot can fly (0 where no mastery is complete),
     * from the session's fetched skills data. Callers check isset($skills['flyable']) first:
     * sessions fetched before ship tree support don't have it.
     */
    public static function sessionLevels(array $skills): array
    {
        $levels = array_fill_keys($skills['flyable'], 0);
        foreach ($skills['masteries'] as $typeId => $mastery) {
            $levels[$typeId] = $mastery['level'];
        }
        return $levels;
    }

    /**
     * Per-faction summary of a pilot's levels.
     *
     * @param  array $levels  [typeID => masteryLevel] for ships the pilot can fly (0 = no mastery)
     * @return array          [factionID => [total, flyable, mastered]] (mastered = mastery V)
     */
    public function summarise(array $levels): array
    {
        $summary = [];
        foreach ($this->getShipIdsByFaction() as $factionId => $typeIds) {
            $flyable  = 0;
            $mastered = 0;
            foreach ($typeIds as $typeId) {
                if (!isset($levels[$typeId])) continue;
                $flyable++;
                if ($levels[$typeId] >= 5) $mastered++;
            }
            $summary[$factionId] = [
                'total'    => count($typeIds),
                'flyable'  => $flyable,
                'mastered' => $mastered,
            ];
        }
        return $summary;
    }

    /**
     * The tree for one faction: root groups, smallest hulls first, each with
     * its specialisation branches.
     *
     * @return array  [['group_id', 'name', 'skills', 'ships', 'mass', 'after', 'starter',
     *                   'branches' => [same shape, no branches]], ...]
     *                 after:   the root group this hull follows (null = a starting hull, or none)
     *                 starter: true when the group has no displayed prerequisites
     *                 from_start: unlinked, but needs a starter's skill (Frigate, Hauler need Spaceship Command)
     */
    public function getTree(int $factionId): array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT t."typeID" AS type_id, t."typeName" AS name, t."shipTreeGroupID" AS group_id,
                   COALESCE(t."techLevel", 1) AS tech_level, COALESCE(t."metaLevel", 0) AS meta_level,
                   t.mass, g.name AS group_name
            FROM evesde."invTypes" t
            JOIN evesde."shipTreeGroups" g ON g."groupID" = t."shipTreeGroupID"
            WHERE t."factionID" = ? AND {$this->treeShipFilter('t')}
            ORDER BY t."metaLevel", t."typeName"
        SQL);
        $stmt->execute([$factionId]);

        $nodes = [];
        $mass  = [];
        foreach ($stmt->fetchAll() as $ship) {
            $groupId = $ship['group_id'];
            $nodes[$groupId] ??= [
                'group_id' => $groupId,
                'name'     => $ship['group_name'],
                'skills'   => [],
                'ships'    => [],
                'branches' => [],
            ];
            $nodes[$groupId]['ships'][] = $ship;
            $mass[$groupId][]           = (float) $ship['mass'];
        }
        if ($nodes === []) {
            return [];
        }

        // Displayed prerequisite skills per group [groupID => [skillID => level]]
        $stmt = $this->db->prepare(<<<SQL
            SELECT p."groupID", p."skillID", p.level,
                   COALESCE(k."typeName", 'Skill #' || p."skillID"::text) AS skill_name
            FROM evesde."shipTreeGroupPreReqSkills" p
            LEFT JOIN evesde."invTypes" k ON k."typeID" = p."skillID"
            WHERE p."factionID" = ? AND p.display = true
            ORDER BY p.level, k."typeName"
        SQL);
        $stmt->execute([$factionId]);

        $reqs = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!isset($nodes[$row['groupID']])) continue;
            $reqs[$row['groupID']][$row['skillID']] = $row['level'];
            $nodes[$row['groupID']]['skills'][] = ['name' => $row['skill_name'], 'level' => $row['level']];
        }

        $parents = [];
        foreach (array_keys($nodes) as $groupId) {
            $parent = $this->findParent($groupId, $reqs[$groupId] ?? [], $reqs);
            if ($parent !== null) {
                $parents[$groupId] = $parent;
            }
        }

        // Second pass for groups that didn't extend another group directly
        $orphans = array_diff(array_keys($nodes), array_keys($parents));
        $deeper  = $this->skillReqs(array_merge(...array_map(
            fn($g) => array_keys($reqs[$g] ?? []), $orphans
        )), 5);
        foreach ($orphans as $groupId) {
            $expanded = $reqs[$groupId] ?? [];
            foreach (array_keys($reqs[$groupId] ?? []) as $skillId) {
                foreach ($deeper[$skillId] ?? [] as $reqSkill => $level) {
                    $expanded[$reqSkill] = max($expanded[$reqSkill] ?? 0, $level);
                }
            }
            $parent = $this->findParent($groupId, $expanded, $reqs);
            if ($parent !== null && !$this->isAncestor($groupId, $parent, $parents)) {
                $parents[$groupId] = $parent;
            }
        }

        $median = array_map(function (array $m): float {
            sort($m);
            return $m[intdiv(count($m), 2)];
        }, $mass);

        // Attach every descendant to its root, so deeper chains stay visible
        $roots = [];
        foreach (array_keys($nodes) as $groupId) {
            $root = $groupId;
            while (isset($parents[$root])) {
                $root = $parents[$root];
            }
            if ($root === $groupId) {
                $roots[$groupId] = $groupId;
            } else {
                $nodes[$root]['branches'][] = $groupId;
            }
        }

        // Roots smallest hull first; branches with T1 navy/faction hulls nearest the root
        uasort($roots, fn($a, $b) => $median[$a] <=> $median[$b] ?: $a <=> $b);
        $after = $this->rootLinks(array_values($roots), $reqs, $median);

        // Skills the starting hulls need, displayed or not (Spaceship Command for Corvette/Shuttle)
        $stmt = $this->db->prepare(
            'SELECT "groupID", "skillID" FROM evesde."shipTreeGroupPreReqSkills" WHERE "factionID" = ?'
        );
        $stmt->execute([$factionId]);
        $starterSkills = [];
        foreach ($stmt->fetchAll() as $row) {
            if (isset($roots[$row['groupID']]) && empty($reqs[$row['groupID']])) {
                $starterSkills[$row['skillID']] = true;
            }
        }
        $needs = $this->skillReqs(array_merge(...array_map(fn($g) => array_keys($reqs[$g] ?? []), array_values($roots))), 1);
        $branchOrder = fn($g) => [min(array_column($nodes[$g]['ships'], 'tech_level')), $median[$g], $g];

        $tree = [];
        foreach ($roots as $groupId) {
            $node = $nodes[$groupId] + [
                'mass'    => $median[$groupId],
                'after'   => $after[$groupId],
                'starter' => empty($reqs[$groupId]),
            ];
            $node['from_start'] = $node['after'] === null && !$node['starter']
                && array_filter(array_keys($reqs[$groupId]), fn($skill) =>
                    array_intersect_key($needs[$skill] ?? [], $starterSkills) !== []
                ) !== [];
            usort($node['branches'], fn($a, $b) => $branchOrder($a) <=> $branchOrder($b));
            $node['branches'] = array_map(fn($g) => $nodes[$g], $node['branches']);
            $tree[] = $node;
        }
        return $tree;
    }

    /** A ship that appears in a ship tree, with its tree group name; null if it isn't one. */
    public function getShip(int $typeId): ?array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT t."typeID" AS type_id, t."typeName" AS name, t."factionID" AS faction_id,
                   COALESCE(t."techLevel", 1) AS tech_level, COALESCE(t."metaLevel", 0) AS meta_level,
                   g.name AS group_name, f."factionName" AS faction_name
            FROM evesde."invTypes" t
            JOIN evesde."shipTreeGroups" g ON g."groupID" = t."shipTreeGroupID"
            LEFT JOIN evesde."chrFactions" f ON f."factionID" = t."factionID"
            WHERE t."typeID" = ? AND {$this->treeShipFilter('t')}
        SQL);
        $stmt->execute([$typeId]);
        return $stmt->fetch() ?: null;
    }

    /** Ships with a /ship/ page whose name contains $query; names starting with it first. */
    public function searchShips(string $query, int $limit): array
    {
        $like = addcslashes($query, '%_\\');
        $stmt = $this->db->prepare(<<<SQL
            SELECT t."typeID" AS type_id, t."typeName" AS name, g.name AS group_name,
                   f."factionName" AS faction_name
            FROM evesde."invTypes" t
            JOIN evesde."shipTreeGroups" g ON g."groupID" = t."shipTreeGroupID"
            LEFT JOIN evesde."chrFactions" f ON f."factionID" = t."factionID"
            WHERE t."typeName" ILIKE :contains AND {$this->treeShipFilter('t')}
            ORDER BY t."typeName" ILIKE :prefix DESC, t."typeName"
            LIMIT :limit
        SQL);
        $stmt->bindValue(':contains', "%{$like}%");
        $stmt->bindValue(':prefix',   "{$like}%");
        $stmt->bindValue(':limit',    $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Skills needed to fly each of several ships, in one query.
     *
     * @param  int[] $typeIds
     * @return array [typeID => [['skill_id', 'level'], ...]]
     */
    public function getFlyRequirementsFor(array $typeIds): array
    {
        $typeIds = array_values(array_unique(array_map('intval', $typeIds)));
        if ($typeIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($typeIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT \"typeID\", \"skillID\" AS skill_id, level FROM evesde.\"shipSkills\" WHERE \"typeID\" IN ({$placeholders})"
        );
        $stmt->execute($typeIds);

        $requirements = [];
        foreach ($stmt->fetchAll() as $row) {
            $requirements[$row['typeID']][] = ['skill_id' => $row['skill_id'], 'level' => $row['level']];
        }
        return $requirements;
    }

    /** Skills needed to fly the ship (direct requirements only). */
    public function getFlyRequirements(int $typeId): array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT s."skillID" AS skill_id, s.level,
                   COALESCE(k."typeName", 'Skill #' || s."skillID"::text) AS name
            FROM evesde."shipSkills" s
            LEFT JOIN evesde."invTypes" k ON k."typeID" = s."skillID"
            WHERE s."typeID" = ?
            ORDER BY s."groupID"
        SQL);
        $stmt->execute([$typeId]);
        return $stmt->fetchAll();
    }

    /**
     * Certificates and skills required for each mastery level.
     * Mastery N needs every listed certificate at level N (certMasteries is 0-based,
     * certSkills 1-based). Skills listed at level 0 aren't required and are dropped.
     *
     * @return array  [1..5 => [['cert_id', 'name', 'description', 'skills' => [['skill_id', 'name', 'level'], ...]], ...]]
     */
    public function getMasteryRequirements(int $typeId): array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT m."masteryLevel" + 1 AS mastery_level, c."certID" AS cert_id, c.name AS cert_name,
                   c.description, s."skillID" AS skill_id, s."skillLevel" AS level,
                   COALESCE(k."typeName", 'Skill #' || s."skillID"::text) AS skill_name
            FROM evesde."certMasteries" m
            JOIN evesde."certCerts" c  ON c."certID" = m."certID"
            JOIN evesde."certSkills" s ON s."certID" = m."certID" AND s."certLevelInt" = m."masteryLevel" + 1
            LEFT JOIN evesde."invTypes" k ON k."typeID" = s."skillID"
            WHERE m."typeID" = ? AND s."skillLevel" > 0
            ORDER BY m."masteryLevel", c.name, k."typeName"
        SQL);
        $stmt->execute([$typeId]);

        $levels = array_fill(1, 5, []);
        foreach ($stmt->fetchAll() as $row) {
            $cert = &$levels[$row['mastery_level']][$row['cert_id']];
            $cert ??= [
                'cert_id'     => $row['cert_id'],
                'name'        => $row['cert_name'],
                'description' => $row['description'],
                'skills'      => [],
            ];
            $cert['skills'][] = ['skill_id' => $row['skill_id'], 'name' => $row['skill_name'], 'level' => $row['level']];
            unset($cert);
        }
        return array_map('array_values', $levels);
    }

    /** Published ships in a tree group that have masteries (excludes the Capsule). */
    private function treeShipFilter(string $alias): string
    {
        return <<<SQL
            {$alias}.published = true AND {$alias}."shipTreeGroupID" IS NOT NULL
            AND EXISTS (SELECT 1 FROM evesde."certMasteries" m WHERE m."typeID" = {$alias}."typeID")
        SQL;
    }

    /**
     * The group whose prerequisites $set strictly extends. When several match,
     * the most specific wins, then the lowest levels (Frigate over Navy Frigate).
     */
    private function findParent(int $groupId, array $set, array $reqs): ?int
    {
        $best = null;
        foreach ($reqs as $candidate => $candReqs) {
            if ($candidate === $groupId || $candReqs === []) continue;

            $strictlyMore = count($set) > count($candReqs);
            foreach ($candReqs as $skillId => $level) {
                $have = $set[$skillId] ?? 0;
                if ($have < $level) continue 2;
                if ($have > $level) $strictlyMore = true;
            }
            if (!$strictlyMore) continue;

            $rank = [count($candReqs), -array_sum($candReqs), -$candidate];
            if ($best === null || $rank > $best[0]) {
                $best = [$rank, $candidate];
            }
        }
        return $best[1] ?? null;
    }

    private function isAncestor(int $groupId, int $of, array $parents): bool
    {
        for ($g = $of; $g !== null; $g = $parents[$g] ?? null) {
            if ($g === $groupId) return true;
        }
        return false;
    }

    /**
     * Which root each root follows: the root whose displayed skill one of its own displayed
     * skills requires (Amarr Destroyer needs Amarr Frigate III → Destroyer follows Frigate).
     * When several match, the largest hull wins.
     *
     * @return array [groupID => groupID|null]
     */
    private function rootLinks(array $roots, array $reqs, array $median): array
    {
        $skillOwner = []; // [skillID => [rootGroupID, ...]]
        foreach ($roots as $groupId) {
            foreach (array_keys($reqs[$groupId] ?? []) as $skillId) {
                $skillOwner[$skillId][] = $groupId;
            }
        }
        $needs = $this->skillReqs(array_keys($skillOwner), 1);

        $after = [];
        foreach ($roots as $groupId) {
            $best = null;
            foreach (array_keys($reqs[$groupId] ?? []) as $skillId) {
                foreach (array_keys($needs[$skillId] ?? []) as $reqSkill) {
                    foreach ($skillOwner[$reqSkill] ?? [] as $candidate) {
                        if ($candidate === $groupId) continue;
                        if ($best === null || $median[$candidate] > $median[$best]) {
                            $best = $candidate;
                        }
                    }
                }
            }
            $after[$groupId] = $best;
        }
        return $after;
    }

    /** [skillID => [requiredSkillID => level]] for direct requirements of the given skills at $minLevel or above. */
    private function skillReqs(array $skillIds, int $minLevel): array
    {
        $skillIds = array_values(array_unique($skillIds));
        if ($skillIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($skillIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT \"typeID\", \"skillID\", level FROM evesde.\"shipSkills\" WHERE level >= ? AND \"typeID\" IN ({$placeholders})"
        );
        $stmt->execute([$minLevel, ...$skillIds]);

        $deeper = [];
        foreach ($stmt->fetchAll() as $row) {
            $deeper[$row['typeID']][$row['skillID']] = $row['level'];
        }
        return $deeper;
    }
}
