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

    public function saveDisplaySpIsk(int $pilotId, ?float $sp, ?float $isk): void
    {
        $displaySp  = $sp  !== null ? $this->roundSigFigs($sp)  : -1;
        $displayIsk = $isk !== null ? $this->roundSigFigs($isk) : -1;

        $this->db->prepare(
            'UPDATE pilots SET sp = ?, isk = ?, updated_at = NOW() WHERE id = ?'
        )->execute([$displaySp, $displayIsk, $pilotId]);
    }

    private function roundSigFigs(float $value, int $figs = 3): float
    {
        if ($value == 0) return 0;
        $magnitude = floor(log10(abs($value)));
        $factor    = pow(10, $figs - 1 - $magnitude);
        return round($value * $factor) / $factor;
    }

    // ── Display selections ────────────────────────────────────────────────────

    /**
     * Replace all display skill selections for a pilot.
     * $skills = [[skill_id, level], ...]
     */
    public function saveDisplaySkills(int $pilotId, array $skills): void
    {
        $this->db->prepare('DELETE FROM pilot_display_skills WHERE pilot_id = ?')->execute([$pilotId]);
        $stmt = $this->db->prepare(
            'INSERT INTO pilot_display_skills (pilot_id, skill_id, level) VALUES (?, ?, ?)'
        );
        foreach ($skills as [$skillId, $level]) {
            $stmt->execute([$pilotId, $skillId, $level]);
        }
    }

    /**
     * Replace all display cert selections for a pilot.
     * $certs = [[cert_id, level], ...]
     */
    public function saveDisplayCerts(int $pilotId, array $certs): void
    {
        $this->db->prepare('DELETE FROM pilot_display_certs WHERE pilot_id = ?')->execute([$pilotId]);
        $stmt = $this->db->prepare(
            'INSERT INTO pilot_display_certs (pilot_id, cert_id, level) VALUES (?, ?, ?)'
        );
        foreach ($certs as [$certId, $level]) {
            $stmt->execute([$pilotId, $certId, $level]);
        }
    }

    /**
     * Replace all display mastery selections for a pilot.
     * $masteries = [[type_id, level], ...]
     */
    public function saveDisplayMasteries(int $pilotId, array $masteries): void
    {
        $this->db->prepare('DELETE FROM pilot_display_masteries WHERE pilot_id = ?')->execute([$pilotId]);
        $stmt = $this->db->prepare(
            'INSERT INTO pilot_display_masteries (pilot_id, type_id, mastery_level) VALUES (?, ?, ?)'
        );
        foreach ($masteries as [$typeId, $level]) {
            $stmt->execute([$pilotId, $typeId, $level]);
        }
    }

    /**
     * Load all display selections for a pilot — for rendering the dashboard checkboxes.
     */
    public function getDisplaySelections(int $pilotId): array
    {
        // Skills with names from evesde
        $skills = $this->db->prepare(<<<SQL
            SELECT ds.skill_id, ds.level AS active_level, ds.level AS trained_level,
                   COALESCE(t."typeName", 'Unknown #' || ds.skill_id::text) AS skill_name
            FROM pilot_display_skills ds
            LEFT JOIN evesde."invTypes" t ON t."typeID" = ds.skill_id
            WHERE ds.pilot_id = ?
            ORDER BY t."typeName"
        SQL);
        $skills->execute([$pilotId]);

        // Certs with names from evesde
        $certs = $this->db->prepare(<<<SQL
            SELECT dc.cert_id, dc.level,
                   COALESCE(c.name, 'Cert #' || dc.cert_id::text) AS cert_name
            FROM pilot_display_certs dc
            LEFT JOIN evesde."certCerts" c ON c."certID" = dc.cert_id
            WHERE dc.pilot_id = ?
            ORDER BY c.name
        SQL);
        $certs->execute([$pilotId]);

        // Masteries with type names from evesde
        $masteries = $this->db->prepare(<<<SQL
            SELECT dm.type_id, dm.mastery_level,
                   COALESCE(t."typeName", 'Type #' || dm.type_id::text) AS type_name
            FROM pilot_display_masteries dm
            LEFT JOIN evesde."invTypes" t ON t."typeID" = dm.type_id
            WHERE dm.pilot_id = ?
            ORDER BY t."typeName"
        SQL);
        $masteries->execute([$pilotId]);

        // SP/ISK display flags
        $spIsk = $this->db->prepare(
            'SELECT sp, isk FROM pilots WHERE id = ?'
        );
        $spIsk->execute([$pilotId]);
        $flags = $spIsk->fetch();

        // For checkbox state — keyed by ID
        $skillsRaw     = $skills->fetchAll();
        $certsRaw      = $certs->fetchAll();
        $masteriesRaw  = $masteries->fetchAll();

        return [
            'skills'      => $skillsRaw,
            'certs'       => $certsRaw,
            'masteries'   => $masteriesRaw,
            // Keyed versions for checkbox checked state in dashboard
            'skill_ids'   => array_column($skillsRaw,    null, 'skill_id'),
            'cert_ids'    => array_column($certsRaw,     null, 'cert_id'),
            'mastery_ids' => array_column($masteriesRaw, null, 'type_id'),
            'show_sp'     => ($flags['sp']  ?? -1) != -1,
            'show_isk'    => ($flags['isk'] ?? -1) != -1,
        ];
    }
}
