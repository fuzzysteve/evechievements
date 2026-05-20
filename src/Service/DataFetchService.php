<?php
declare(strict_types=1);
namespace App\Service;

use App\Config\Database;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Fetches ESI data for a section using a one-time access token,
 * stores the result in the session, then discards the token.
 */
final class DataFetchService
{
    public function __construct(
        private readonly EsiService      $esi,
        private readonly LoggerInterface $logger
    ) {}

    public function fetch(string $section, int $characterId, string $accessToken): void
    {
        try {
            $data = match($section) {
                'skills' => $this->fetchSkills($characterId, $accessToken),
                'wallet' => $this->fetchWallet($characterId, $accessToken),
                'assets' => $this->fetchAssets($characterId, $accessToken),
                default  => throw new \RuntimeException("Unknown section: {$section}"),
            };

            $_SESSION['fetched'][$section]    = $data;
            $_SESSION['fetched_at'][$section] = time();

        } catch (\Throwable $e) {
            $this->logger->error("DataFetch [{$section}] failed", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public static function hasFresh(string $section, int $ttl = 3600): bool
    {
        $fetchedAt = $_SESSION['fetched_at'][$section] ?? 0;
        return isset($_SESSION['fetched'][$section]) && (time() - $fetchedAt) < $ttl;
    }

    public static function clear(string $section): void
    {
        unset($_SESSION['fetched'][$section], $_SESSION['fetched_at'][$section]);
    }

    // ── Section fetchers ──────────────────────────────────────────────────────

    private function fetchSkills(int $characterId, string $token): array
    {
        $data    = $this->esi->getSkills($characterId, $token);
        $skills  = $data['skills'] ?? [];
        $totalSp = $data['total_sp'] ?? array_sum(array_column($skills, 'skillpoints_in_skill'));

        // Build skill level lookup [skill_id => active_skill_level]
        $skillLevels = [];
        foreach ($skills as $skill) {
            $skillLevels[$skill['skill_id']] = $skill['active_skill_level'];
        }

        // Resolve skill names from evesde
        $db           = Database::connect();
        $ids          = array_keys($skillLevels);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $db->prepare(
            "SELECT \"typeID\", \"typeName\" FROM evesde.\"invTypes\" WHERE \"typeID\" IN ({$placeholders})"
        );
        $stmt->execute($ids);
        $names = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // [typeID => typeName]

        foreach ($skills as &$skill) {
            $skill['skill_name'] = $names[$skill['skill_id']] ?? "Unknown #{$skill['skill_id']}";
        }
        unset($skill);

        usort($skills, fn($a, $b) => strcmp($a['skill_name'], $b['skill_name']));

        // Calculate completed certs and masteries
        [$certs, $masteries] = $this->calculateCertsAndMasteries($skillLevels, $db);

        return [
            'total_sp'  => $totalSp,
            'skills'    => $skills,
            'certs'     => $certs,
            'masteries' => $masteries,
        ];
    }

    /**
     * Calculate highest completed level for each cert and mastery.
     *
     * @param  array $skillLevels  [skill_id => active_skill_level]
     * @return array               [certs, masteries]
     *                             certs:     [certID => highestLevel]
     *                             masteries: [typeID => highestLevel]
     */
    private function calculateCertsAndMasteries(array $skillLevels, PDO $db): array
    {   


	// Load ship skill requirements [typeID][skillID => level]
        $stmt = $db->query(
            'SELECT "typeID", "skillID", "level" FROM evesde."shipSkills"'
        );
        $shipSkillReqs = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $shipSkillReqs[$row['typeID']][$row['skillID']] = $row['level'];
        }

        // Load all cert skill requirements in one query
        // [certID][certLevelInt] => [[skillID, skillLevel], ...]
        $stmt = $db->query(
            'SELECT "certID", "certLevelInt", "skillID", "skillLevel" FROM evesde."certSkills" ORDER BY "certID", "certLevelInt"'
        );
        $certSkills = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $certSkills[$row['certID']][$row['certLevelInt']][] = [
                'skill_id' => $row['skillID'],
                'required' => $row['skillLevel'],
            ];
        }

        // Calculate highest completed level per cert
        $completedCerts = []; // [certID => highestLevel]
        foreach ($certSkills as $certId => $levels) {
            $highest = 0;
            foreach ($levels as $level => $requirements) {
                $complete = true;
                foreach ($requirements as $req) {
                    $pilotLevel = $skillLevels[$req['skill_id']] ?? 0;
                    if ($pilotLevel < $req['required']) {
                        $complete = false;
                        break;
                    }
                }
                if ($complete) {
                    $highest = max($highest, $level);
                }
            }
            if ($highest > 0) {
                $completedCerts[$certId] = $highest;
            }
        }

        // Load mastery requirements
        // [typeID][masteryLevel] => [certID, ...]
            #'SELECT "typeID", "masteryLevel", "certID" FROM evesde."certMasteries" join evesde."invTypes" on evesde."certMasteries"."typeID"=evesde."invTypes"."typeID" where evesde."invTypes".published=true ORDER BY "typeID", "masteryLevel"'
        $stmt = $db->query(
            'SELECT "certMasteries"."typeID", "masteryLevel", "certID" FROM evesde."certMasteries" join evesde."invTypes" on evesde."certMasteries"."typeID"=evesde."invTypes"."typeID" join evesde."invGroups" on evesde."invTypes"."groupID"=evesde."invGroups"."groupID" where evesde."invTypes".published=true and evesde."invGroups"."categoryID"=6 ORDER BY "typeID", "masteryLevel"'
        );
        $masteryReqs = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $masteryReqs[$row['typeID']][$row['masteryLevel']][] = $row['certID'];
        }

        // Calculate highest completed mastery level per ship/type
        // A mastery level is complete when ALL its required certs are complete
        // at ANY level (the cert just needs to be completed, not at a specific level)
        $completedMasteries = []; // [typeID => highestLevel]
	foreach ($masteryReqs as $typeId => $levels) {
            // Check pilot can fly the ship first
            $canFly = true;
            foreach ($shipSkillReqs[$typeId] ?? [] as $skillId => $required) {
                if (($skillLevels[$skillId] ?? 0) < $required) {
                    $canFly = false;
                    break;
                }
            }
            if (!$canFly) continue;




            $highest = 0;
            foreach ($levels as $masteryLevel => $certIds) {
		$complete = true;
                foreach ($certIds as $certId) {
                    $completedLevel = $completedCerts[$certId] ?? 0;
                    if ($completedLevel < $masteryLevel+1) {  // cert must be complete at >= mastery level
                        $complete = false;
                        break;
                    }
                }
                if ($complete) {
                    $highest = max($highest, $masteryLevel + 1);
                }
            }
            if ($highest > 0) {
                $completedMasteries[$typeId] = $highest;
            }
        }

        // Resolve cert names
        $certIds      = array_keys($completedCerts);
        $certNames    = [];
        if (!empty($certIds)) {
            $placeholders = implode(',', array_fill(0, count($certIds), '?'));
            $stmt = $db->prepare(
                "SELECT \"certID\", name FROM evesde.\"certCerts\" WHERE \"certID\" IN ({$placeholders})"
            );
            $stmt->execute($certIds);
            $certNames = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        }

        // Resolve mastery type names
        $typeIds   = array_keys($completedMasteries);
        $typeNames = [];
        if (!empty($typeIds)) {
            $placeholders = implode(',', array_fill(0, count($typeIds), '?'));
            $stmt = $db->prepare(
                "SELECT \"typeID\", \"typeName\" FROM evesde.\"invTypes\" WHERE \"typeID\" IN ({$placeholders})"
            );
            $stmt->execute($typeIds);
            $typeNames = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        }

        // Attach names to results
        $namedCerts = [];
        foreach ($completedCerts as $certId => $level) {
            $namedCerts[$certId] = [
                'level' => $level,
                'name'  => $certNames[$certId] ?? "Cert #{$certId}",
            ];
        }

        $namedMasteries = [];
        foreach ($completedMasteries as $typeId => $level) {
            $namedMasteries[$typeId] = [
                'level' => $level,
                'name'  => $typeNames[$typeId] ?? "Type #{$typeId}",
            ];
        }

        // Sort both by name
        uasort($namedCerts,     fn($a, $b) => strcmp($a['name'], $b['name']));
        uasort($namedMasteries, fn($a, $b) => strcmp($a['name'], $b['name']));

        return [$namedCerts, $namedMasteries];
    }

    private function fetchWallet(int $characterId, string $token): array
    {
        $balance = $this->esi->getWalletBalance($characterId, $token);
        return ['balance' => $balance];
    }

    private function fetchAssets(int $characterId, string $token): array
    {
        $assets = $this->esi->getAssets($characterId, $token);
        $db     = Database::connect();

        // ── Resolve type names from evesde ────────────────────────────────
        $typeIds      = array_values(array_unique(array_column($assets, 'type_id')));
        $placeholders = implode(',', array_fill(0, count($typeIds), '?'));
        $stmt         = $db->prepare(
            "SELECT \"typeID\", \"typeName\" FROM evesde.\"invTypes\" WHERE \"typeID\" IN ({$placeholders})"
        );
        $stmt->execute($typeIds);
        $typeNames = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // [typeID => typeName]

        // ── Resolve location names from mapDenormalize (id <= 65000000 only) ──
        $locationIds = array_values(array_unique(array_column($assets, 'location_id')));
        $resolveIds  = array_values(array_filter($locationIds, fn($id) => $id <= 65000000));

        $locationNames = [];
        if (!empty($resolveIds)) {
            $placeholders = implode(',', array_fill(0, count($resolveIds), '?'));
            $stmt = $db->prepare(
                "SELECT \"itemID\", \"itemName\" FROM evesde.\"mapDenormalize\" WHERE \"itemID\" IN ({$placeholders})"
            );
            $stmt->execute(array_values($resolveIds));
            $locationNames = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // [itemID => itemName]
        }

        // ── Get adjusted prices (no auth needed) ─────────────────────────
        $prices     = $this->esi->getMarketPrices();
        $totalValue = 0.0;

        // ── Group assets by location, aggregate quantities per type ───────
        $byLocation = []; // [locationId => [typeName => [type_id, quantity, value]]]

        foreach ($assets as $asset) {
            $typeId     = $asset['type_id'];
            $locationId = $asset['location_id'];
            $quantity   = $asset['quantity'] ?? 1;
            $typeName   = $typeNames[$typeId] ?? "Unknown #{$typeId}";

            // Accumulate value
            $adjustedPrice = $prices[$typeId]['average_price'] ?? 0;
            $totalValue   += $adjustedPrice * $quantity;

            // Group by location
            $locKey = $locationId;
            if (!isset($byLocation[$locKey])) {
                $byLocation[$locKey] = [
                    'location_id'   => $locationId,
                    'location_name' => $locationNames[$locationId] ?? 'Unknown Location',
                    'items'         => [],
                    'item_count'    => 0,
                ];
            }

            // Aggregate quantities for same type in same location
            if (isset($byLocation[$locKey]['items'][$typeId])) {
                $byLocation[$locKey]['items'][$typeId]['quantity'] += $quantity;
            } else {
                $byLocation[$locKey]['items'][$typeId] = [
                    'type_id'   => $typeId,
                    'type_name' => $typeName,
                    'quantity'  => $quantity,
                ];
            }

            $byLocation[$locKey]['item_count']++;
        }

        // Sort locations by name, items within each location by name
        foreach ($byLocation as &$loc) {
            usort($loc['items'], fn($a, $b) => strcmp($a['type_name'], $b['type_name']));
            $loc['items'] = array_values($loc['items']);
        }
        unset($loc);

        usort($byLocation, fn($a, $b) => strcmp($a['location_name'], $b['location_name']));

        // Also build a flat list of all types with total quantities across all locations
        // for the display selection (user picks types to show, not locations)
	$allTypes = [];
	foreach ($assets as $asset) {
            $typeId   = $asset['type_id'];
            $quantity = $asset['quantity'] ?? 1;
            $adjustedPrice = $prices[$typeId]['adjusted_price'] ?? 0;

            if (isset($allTypes[$typeId])) {
                $allTypes[$typeId]['quantity']    += $quantity;
                $allTypes[$typeId]['total_value'] += $adjustedPrice * $quantity;
            } else {
                $allTypes[$typeId] = [
                    'type_id'     => $typeId,
                    'type_name'   => $typeNames[$typeId] ?? "Unknown #{$typeId}",
                    'quantity'    => $quantity,
                    'unit_price'  => $adjustedPrice,
                    'total_value' => $adjustedPrice * $quantity,
		];
	    }
        }

        usort($allTypes, fn($a, $b) => strcmp($a['type_name'], $b['type_name']));

        return [
            'total_value' => $totalValue,
            'total_count' => count($assets),
            'by_location' => $byLocation,
            'all_types'   => array_values($allTypes),
        ];
    }
}
