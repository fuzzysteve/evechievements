<?php
declare(strict_types=1);
namespace App\Service;

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

    /**
     * Fetch data for the given section, store in session, discard token.
     */
    public function fetch(string $section, int $characterId, string $accessToken): void
    {
        try {
            $data = match($section) {
                'skills' => $this->fetchSkills($characterId, $accessToken),
                'wallet' => $this->fetchWallet($characterId, $accessToken),
                'assets' => $this->fetchAssets($characterId, $accessToken),
                default  => throw new \RuntimeException("Unknown section: {$section}"),
            };

            $_SESSION['fetched'][$section]            = $data;
            $_SESSION['fetched_at'][$section]         = time();

        } catch (\Throwable $e) {
            $this->logger->error("DataFetch [{$section}] failed", ['error' => $e->getMessage()]);
            throw $e;
        }
        // Token is simply not stored — it goes out of scope here
    }

    /**
     * Check if a section has been fetched and is still fresh (default 1 hour).
     */
    public static function hasFresh(string $section, int $ttl = 3600): bool
    {
        $fetchedAt = $_SESSION['fetched_at'][$section] ?? 0;
        return isset($_SESSION['fetched'][$section]) && (time() - $fetchedAt) < $ttl;
    }

    /**
     * Clear a section from the session (force re-fetch).
     */
    public static function clear(string $section): void
    {
        unset($_SESSION['fetched'][$section], $_SESSION['fetched_at'][$section]);
    }

    // ── Section fetchers ──────────────────────────────────────────────────────

    private function fetchSkills(int $characterId, string $token): array
    {
        $data    = $this->esi->getSkills($characterId, $token);
        $skills  = $data['skills'] ?? [];
        $totalSp = $data['total_sp'];
    
        // Resolve skill names from evesde in one query
        $ids         = array_column($skills, 'skill_id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
    
        $db   = \App\Config\Database::connect();
        $stmt = $db->prepare(
            "SELECT \"typeID\", \"typeName\" FROM evesde.\"invTypes\" WHERE \"typeID\" IN ({$placeholders})"
        );
        $stmt->execute($ids);
        $names = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR); // [typeID => typeName]
    
        // Attach names and sort by name
        foreach ($skills as &$skill) {
            $skill['skill_name'] = $names[$skill['skill_id']] ?? "Unknown #{$skill['skill_id']}";
        }
        unset($skill);
    
        usort($skills, fn($a, $b) => strcmp($a['skill_name'], $b['skill_name']));
    
        return [
            'total_sp' => $totalSp,
            'skills'   => $skills,
        ];
    }


    private function fetchWallet(int $characterId, string $token): array
    {
        // Wallet balance endpoint returns a plain float
        $balance = $this->esi->getWalletBalance($characterId, $token);

        return [
            'balance' => $balance,
        ];
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
