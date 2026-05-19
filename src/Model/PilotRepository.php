<?php
declare(strict_types=1);
namespace App\Model;

use App\Config\Database;
use App\Service\EsiService;
use PDO;

final class PilotRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM pilots WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByName(string $name): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM pilots WHERE LOWER(name) = LOWER(?)');
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        return $row ?: null;
    }



    public function createById(int $characterId, EsiService $esi): array
    {
        $char    = $esi->getCharacter($characterId);
    
        $corpName     = null;
        $allianceName = null;

        if (isset($char['corporation_id'])) {
            $corp     = $esi->getCorporation($char['corporation_id']);
        $corpName = $corp['name'] ?? null;
            $corpTicker  = $corp['ticker'] ?? null;
        }
        if (isset($char['alliance_id'])) {
            $alliance     = $esi->getAlliance($char['alliance_id']);
        $allianceName = $alliance['name'] ?? null;
        $allianceTicker = $alliance['ticker'] ?? null;
        }

        $this->db->prepare(<<<SQL
            INSERT INTO pilots
                 (id, name, corporation_id, corporation_name, alliance_id, alliance_name,
                 corporation_ticker,alliance_ticker,
                 security_status, birthday, race_id, bloodline_id, created_at, updated_at)
            VALUES
                (:id, :name, :corp_id, :corp_name, :alliance_id, :alliance_name,
                 :corpticker,:allianceticker,
                 :sec, :birthday, :race_id, :bloodline_id, NOW(), NOW())
            ON CONFLICT (id) DO NOTHING
        SQL)->execute([
            'id'           => $characterId,
            'name'         => $char['name'],
            'corp_id'      => $char['corporation_id'] ?? null,
            'corp_name'    => $corpName,
            'alliance_id'  => $char['alliance_id'] ?? null,
            'alliance_name'=> $allianceName,
            'corpticker'   => $corpTicker,
            'allianceticker'   => $allianceTicker,
            'sec'          => $char['security_status'] ?? 0,
            'birthday'     => $char['birthday'] ?? null,
            'race_id'      => $char['race_id'] ?? null,
            'bloodline_id' => $char['bloodline_id'] ?? null,
        ]);

        return $this->findById($characterId);
    }
    /**
     * Public pilot listing for the Browse page.
     */
    public function getPublicPilots(int $page = 1, int $perPage = 24): array
    {
        $offset = ($page - 1) * $perPage;
        $stmt   = $this->db->prepare(<<<SQL
            SELECT *
            FROM pilots p
            WHERE p.is_public = true
            GROUP BY p.id
            LIMIT :limit OFFSET :offset
        SQL);
        $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function countPublic(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM pilots WHERE is_public = true')->fetchColumn();
    }

    /**
     * Pilot stats for the profile page.
     */
    public function getStats(int $pilotId): array
    {
        $row = $this->db->prepare(<<<SQL
            SELECT isk total_isk, sp total_sp, asset_value asset_value,birthday birthday
            FROM pilots p WHERE p.id = ?
        SQL);
        $row->execute([$pilotId]);
        return $row->fetch() ?: [];
    }

    public function setPublic(int $pilotId, bool $isPublic): void
    {
        $this->db->prepare('UPDATE pilots SET is_public = ?, updated_at = NOW() WHERE id = ?')
                 ->execute([$isPublic ? 'true' : 'false', $pilotId]);
    }
}
