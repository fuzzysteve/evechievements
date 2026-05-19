<?php
declare(strict_types=1);
namespace App\Service;

use App\Config\Database;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Evaluates trophy criteria against a pilot's synced data and awards trophies.
 */
final class TrophyService
{
    private PDO $db;

    public function __construct(private readonly LoggerInterface $logger)
    {
        $this->db = Database::connect();
    }

    /**
     * Run all trophy checks for a pilot and award newly-earned trophies.
     * Returns list of newly awarded trophy slugs.
     */
    public function evaluate(int $pilotId): array
    {
        $trophies = $this->db->query('SELECT * FROM trophies ORDER BY points ASC')->fetchAll();
        $awarded  = [];

        foreach ($trophies as $trophy) {
            if ($this->alreadyEarned($pilotId, $trophy['id'])) {
                continue;
            }

            $criteria = json_decode($trophy['criteria_json'], true);
            $result   = $this->check($pilotId, $criteria);

            if ($result['earned']) {
                $this->awardTrophy($pilotId, $trophy['id'], $result['evidence']);
                $awarded[] = $trophy['slug'];
                $this->logger->info("Trophy awarded: {$trophy['slug']} to pilot:{$pilotId}");
            }
        }

        return $awarded;
    }

    private function check(int $pilotId, array $criteria): array
    {
        return match($criteria['type']) {
            'total_sp'             => $this->checkTotalSP($pilotId, $criteria['threshold']),
            'skills_trained_count' => $this->checkSkillsCount($pilotId, $criteria['threshold']),
            'wallet_inflow_total'  => $this->checkWalletInflow($pilotId, $criteria['threshold']),
            'corp_count'           => $this->checkCorpCount($pilotId, $criteria['threshold']),
            'account_age_days'     => $this->checkAccountAge($pilotId, $criteria['threshold']),
            default                => ['earned' => false, 'evidence' => []],
        };
    }

    private function checkTotalSP(int $pilotId, int $threshold): array
    {
        $row = $this->db->prepare(
            'SELECT SUM(skillpoints_in_skill) AS total FROM pilot_skills WHERE pilot_id = ?'
        );
        $row->execute([$pilotId]);
        $total = (int) ($row->fetchColumn() ?? 0);
        return [
            'earned'   => $total >= $threshold,
            'evidence' => ['total_sp' => $total, 'threshold' => $threshold],
        ];
    }

    private function checkSkillsCount(int $pilotId, int $threshold): array
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM pilot_skills WHERE pilot_id = ? AND trained_level >= 1'
        );
        $stmt->execute([$pilotId]);
        $count = (int) $stmt->fetchColumn();
        return [
            'earned'   => $count >= $threshold,
            'evidence' => ['skills_count' => $count, 'threshold' => $threshold],
        ];
    }

    private function checkWalletInflow(int $pilotId, float $threshold): array
    {
        $stmt = $this->db->prepare(
            "SELECT SUM(amount) FROM wallet_journal WHERE pilot_id = ? AND amount > 0"
        );
        $stmt->execute([$pilotId]);
        $total = (float) ($stmt->fetchColumn() ?? 0);
        return [
            'earned'   => $total >= $threshold,
            'evidence' => ['total_inflow' => $total, 'threshold' => $threshold],
        ];
    }

    private function checkCorpCount(int $pilotId, int $threshold): array
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(DISTINCT corporation_id) FROM corp_history WHERE pilot_id = ?'
        );
        $stmt->execute([$pilotId]);
        $count = (int) $stmt->fetchColumn();
        return [
            'earned'   => $count >= $threshold,
            'evidence' => ['corp_count' => $count, 'threshold' => $threshold],
        ];
    }

    private function checkAccountAge(int $pilotId, int $days): array
    {
        $stmt = $this->db->prepare(
            "SELECT EXTRACT(DAY FROM NOW() - birthday) AS age FROM pilots WHERE id = ?"
        );
        $stmt->execute([$pilotId]);
        $age = (int) ($stmt->fetchColumn() ?? 0);
        return [
            'earned'   => $age >= $days,
            'evidence' => ['account_age_days' => $age, 'threshold' => $days],
        ];
    }

    private function alreadyEarned(int $pilotId, string $trophyId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM pilot_trophies WHERE pilot_id = ? AND trophy_id = ?'
        );
        $stmt->execute([$pilotId, $trophyId]);
        return (bool) $stmt->fetchColumn();
    }

    private function awardTrophy(int $pilotId, string $trophyId, array $evidence): void
    {
        $this->db->prepare(<<<SQL
            INSERT INTO pilot_trophies (pilot_id, trophy_id, earned_at, evidence_json)
            VALUES (:pilot_id, :trophy_id, NOW(), :evidence)
            ON CONFLICT (pilot_id, trophy_id) DO NOTHING
        SQL)->execute([
            'pilot_id'  => $pilotId,
            'trophy_id' => $trophyId,
            'evidence'  => json_encode($evidence),
        ]);
    }

    /**
     * Get a pilot's trophies with full trophy info, ordered by pinned + earned date.
     */
    public function getPilotTrophies(int $pilotId): array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT t.*, pt.earned_at, pt.is_pinned, pt.display_order,
                   pt.evidence_json,
                   COUNT(pt2.pilot_id) AS earned_by_count,
                   ROUND(
                       COUNT(pt2.pilot_id)::numeric / GREATEST((SELECT COUNT(*) FROM pilots),1) * 100,
                       1
                   ) AS earned_pct
            FROM pilot_trophies pt
            JOIN trophies t ON t.id = pt.trophy_id
            LEFT JOIN pilot_trophies pt2 ON pt2.trophy_id = t.id
            WHERE pt.pilot_id = :pilot_id
            GROUP BY t.id, pt.earned_at, pt.is_pinned, pt.display_order, pt.evidence_json
            ORDER BY pt.is_pinned DESC, pt.earned_at DESC
        SQL);
        $stmt->execute(['pilot_id' => $pilotId]);
        return $stmt->fetchAll();
    }

    /**
     * Recent trophy earns across all public pilots (for the homepage feed).
     */
    public function getRecentPublicTrophies(int $limit = 12): array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT t.*, pt.earned_at,
                   p.id AS pilot_id, p.name AS pilot_name,
                   p.portrait_64, p.corporation_name, p.alliance_name,
                   COUNT(pt2.pilot_id)::float /
                       GREATEST((SELECT COUNT(*) FROM pilots), 1) * 100 AS earned_pct
            FROM pilot_trophies pt
            JOIN trophies t    ON t.id = pt.trophy_id
            JOIN pilots p      ON p.id = pt.pilot_id
            LEFT JOIN pilot_trophies pt2 ON pt2.trophy_id = t.id
            WHERE p.is_public = true
            GROUP BY t.id, pt.earned_at, p.id, p.name, p.portrait_64,
                     p.corporation_name, p.alliance_name
            ORDER BY pt.earned_at DESC
            LIMIT :limit
        SQL);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
