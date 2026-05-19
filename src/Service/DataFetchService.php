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
        $stmt = $db->query(
            'SELECT "typeID", "masteryLevel", "certID" FROM evesde."certMasteries" ORDER BY "typeID", "masteryLevel"'
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
            $highest = 0;
            foreach ($levels as $masteryLevel => $certIds) {
                $complete = true;
                foreach ($certIds as $certId) {
                    if (!isset($completedCerts[$certId])) {
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
        return [
            'count'  => count($assets),
            'assets' => $assets,
        ];
    }
}
