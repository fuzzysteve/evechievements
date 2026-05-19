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


    public function getPublicPilots(int $page = 1, int $perPage = 24): array
    {
        $offset = ($page - 1) * $perPage;
        $stmt   = $this->db->prepare(<<<SQL
            SELECT p.*,
                   COUNT(pt.trophy_id) AS trophy_count,
                   SUM(CASE WHEN t.rarity = 'epic' THEN 1 ELSE 0 END) AS epic_count
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

    public function getStats(int $pilotId): array
    {
        $row = $this->db->prepare(<<<SQL
            SELECT
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

    // ── SP / ISK / Assets value ───────────────────────────────────────────────

    public function saveDisplaySpIsk(int $pilotId, ?float $sp, ?float $isk): void
    {
        $displaySp  = $sp  !== null ? $this->roundSigFigs($sp)  : -1;
        $displayIsk = $isk !== null ? $this->roundSigFigs($isk) : -1;
        $this->db->prepare(
            'UPDATE pilots SET sp = ?, isk = ?, updated_at = NOW() WHERE id = ?'
        )->execute([$displaySp, $displayIsk, $pilotId]);
    }

    public function saveAssetsValue(int $pilotId, ?float $value): void
    {
        $rounded = $value !== null ? $this->roundSigFigs($value) : -1;
        $this->db->prepare(
            'UPDATE pilots SET assets_value = ?, updated_at = NOW() WHERE id = ?'
        )->execute([$rounded, $pilotId]);
    }

    private function roundSigFigs(float $value, int $figs = 3): float
    {
        if ($value == 0) return 0;
        $magnitude = floor(log10(abs($value)));
        $factor    = pow(10, $figs - 1 - $magnitude);
        return round($value * $factor) / $factor;
    }

    // ── Display selections ────────────────────────────────────────────────────

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

    public function saveDisplayAssets(int $pilotId, array $assets): void
    {
        $this->db->prepare('DELETE FROM pilot_display_assets WHERE pilot_id = ?')->execute([$pilotId]);
        $stmt = $this->db->prepare(
            'INSERT INTO pilot_display_assets (pilot_id, type_id, quantity) VALUES (?, ?, ?)'
        );
        foreach ($assets as [$typeId, $quantity]) {
            $stmt->execute([$pilotId, $typeId, $quantity]);
        }
    }

    public function getDisplayAssets(int $pilotId): array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT da.type_id, da.quantity,
                   COALESCE(t."typeName", 'Unknown #' || da.type_id::text) AS type_name
            FROM pilot_display_assets da
            LEFT JOIN evesde."invTypes" t ON t."typeID" = da.type_id
            WHERE da.pilot_id = ?
            ORDER BY t."typeName"
        SQL);
        $stmt->execute([$pilotId]);
        return $stmt->fetchAll();
    }

    public function getDisplaySelections(int $pilotId): array
    {
        $skills = $this->db->prepare(<<<SQL
            SELECT ds.skill_id, ds.level AS active_level, ds.level AS trained_level,
                   COALESCE(t."typeName", 'Unknown #' || ds.skill_id::text) AS skill_name
            FROM pilot_display_skills ds
            LEFT JOIN evesde."invTypes" t ON t."typeID" = ds.skill_id
            WHERE ds.pilot_id = ?
            ORDER BY t."typeName"
        SQL);
        $skills->execute([$pilotId]);

        $certs = $this->db->prepare(<<<SQL
            SELECT dc.cert_id, dc.level,
                   COALESCE(c.name, 'Cert #' || dc.cert_id::text) AS cert_name
            FROM pilot_display_certs dc
            LEFT JOIN evesde."certCerts" c ON c."certID" = dc.cert_id
            WHERE dc.pilot_id = ?
            ORDER BY c.name
        SQL);
        $certs->execute([$pilotId]);

        $masteries = $this->db->prepare(<<<SQL
            SELECT dm.type_id, dm.mastery_level,
                   COALESCE(t."typeName", 'Type #' || dm.type_id::text) AS type_name
            FROM pilot_display_masteries dm
            LEFT JOIN evesde."invTypes" t ON t."typeID" = dm.type_id
            WHERE dm.pilot_id = ?
            ORDER BY t."typeName"
        SQL);
        $masteries->execute([$pilotId]);

        $flags = $this->db->prepare('SELECT sp, isk, assets_value FROM pilots WHERE id = ?');
        $flags->execute([$pilotId]);
        $f = $flags->fetch();

        $skillsRaw    = $skills->fetchAll();
        $certsRaw     = $certs->fetchAll();
        $masteriesRaw = $masteries->fetchAll();
        $assetsRaw    = $this->getDisplayAssets($pilotId);

        return [
            'skills'       => $skillsRaw,
            'certs'        => $certsRaw,
            'masteries'    => $masteriesRaw,
            'skill_ids'    => array_column($skillsRaw,    null, 'skill_id'),
            'cert_ids'     => array_column($certsRaw,     null, 'cert_id'),
            'mastery_ids'  => array_column($masteriesRaw, null, 'type_id'),
            'asset_ids'    => array_column($assetsRaw,    null, 'type_id'),
            'show_sp'      => ($f['sp']           ?? -1) != -1,
            'show_isk'     => ($f['isk']          ?? -1) != -1,
            'show_assets'  => ($f['assets_value'] ?? -1) != -1,
        ];
    }

    public function createById(int $characterId, \App\Service\EsiService $esi): ?array
    {
        $char = $esi->getCharacter($characterId);

        $corpName       = null;
        $corpTicker     = null;
        $allianceName   = null;
        $allianceTicker = null;

        if (isset($char['corporation_id'])) {
            $corp       = $esi->getCorporation($char['corporation_id']);
            $corpName   = $corp['name']   ?? null;
            $corpTicker = $corp['ticker'] ?? null;
        }
        if (isset($char['alliance_id'])) {
            $alliance       = $esi->getAlliance($char['alliance_id']);
            $allianceName   = $alliance['name']   ?? null;
            $allianceTicker = $alliance['ticker'] ?? null;
        }

        $this->db->prepare(<<<SQL
            INSERT INTO pilots
                (id, name, corporation_id, corporation_name, corporation_ticker,
                 alliance_id, alliance_name, alliance_ticker,
                 security_status, birthday, race_id, bloodline_id, created_at, updated_at)
            VALUES
                (:id, :name, :corp_id, :corp_name, :corp_ticker,
                 :alliance_id, :alliance_name, :alliance_ticker,
                 :sec, :birthday, :race_id, :bloodline_id, NOW(), NOW())
            ON CONFLICT (id) DO NOTHING
        SQL)->execute([
            'id'             => $characterId,
            'name'           => $char['name'],
            'corp_id'        => $char['corporation_id']  ?? null,
            'corp_name'      => $corpName,
            'corp_ticker'    => $corpTicker,
            'alliance_id'    => $char['alliance_id']     ?? null,
            'alliance_name'  => $allianceName,
            'alliance_ticker'=> $allianceTicker,
            'sec'            => $char['security_status'] ?? 0,
            'birthday'       => $char['birthday']        ?? null,
            'race_id'        => $char['race_id']         ?? null,
            'bloodline_id'   => $char['bloodline_id']    ?? null,
        ]);

        return $this->findById($characterId);
    }
}
