<?php
declare(strict_types=1);
namespace App\Model;

use App\Config\Database;
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

    /**
     * Create or update a pilot row after OAuth login.
     */
    public function upsert(array $data): array
    {
        $this->db->prepare(<<<SQL
            INSERT INTO pilots (id, name, access_token, refresh_token, token_expires, token_scopes, updated_at)
            VALUES (:id, :name, :access, :refresh, :expires, :scopes, NOW())
            ON CONFLICT (id) DO UPDATE SET
                name          = EXCLUDED.name,
                access_token  = EXCLUDED.access_token,
                refresh_token = EXCLUDED.refresh_token,
                token_expires = EXCLUDED.token_expires,
                token_scopes  = EXCLUDED.token_scopes,
                updated_at    = NOW()
        SQL)->execute([
            'id'      => $data['id'],
            'name'    => $data['name'],
            'access'  => $data['access_token'],
            'refresh' => $data['refresh_token'],
            'expires' => $data['token_expires'],
            'scopes'  => '{' . implode(',', $data['scopes'] ?? []) . '}',
        ]);
        return $this->findById($data['id']);
    }

    /**
     * Public pilot listing for the Browse page.
     */
    public function getPublicPilots(int $page = 1, int $perPage = 24): array
    {
        $offset = ($page - 1) * $perPage;
        $stmt   = $this->db->prepare(<<<SQL
            SELECT p.*,
                   COUNT(pt.trophy_id) AS trophy_count,
                   SUM(CASE WHEN t.rarity = 'epic' THEN 1 ELSE 0 END) AS epic_count,
                   (SELECT SUM(skillpoints_in_skill) FROM pilot_skills WHERE pilot_id = p.id) AS total_sp
            FROM pilots p
            LEFT JOIN pilot_trophies pt ON pt.pilot_id = p.id
            LEFT JOIN trophies t ON t.id = pt.trophy_id
            WHERE p.is_public = true
            GROUP BY p.id
            ORDER BY trophy_count DESC, p.updated_at DESC
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
            SELECT
                (SELECT SUM(skillpoints_in_skill) FROM pilot_skills WHERE pilot_id = p.id) AS total_sp,
                (SELECT COUNT(*) FROM pilot_skills WHERE pilot_id = p.id AND trained_level = 5) AS skills_at_v,
                (SELECT COUNT(*) FROM killmails WHERE pilot_id = p.id AND is_victim = false) AS kills,
                (SELECT COUNT(*) FROM killmails WHERE pilot_id = p.id AND is_victim = true)  AS losses,
                (SELECT COUNT(DISTINCT corporation_id) FROM corp_history WHERE pilot_id = p.id) AS corps_count,
                EXTRACT(YEAR FROM AGE(NOW(), p.birthday)) AS years_old
            FROM pilots p WHERE p.id = ?
        SQL);
        $row->execute([$pilotId]);
        return $row->fetch() ?: [];
    }

    public function setPublic(int $pilotId, bool $isPublic): void
    {
        $this->db->prepare('UPDATE pilots SET is_public = ?, updated_at = NOW() WHERE id = ?')
                 ->execute([$isPublic, $pilotId]);
    }
}
