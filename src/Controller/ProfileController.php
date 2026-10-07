<?php
declare(strict_types=1);
namespace App\Controller;

use App\Model\PilotRepository;
use App\Service\ShipTreeLayout;
use App\Service\ShipTreeService;
use App\Config\Database;
use Twig\Environment;

final class ProfileController
{
    public function __construct(
        private readonly Environment     $twig,
        private readonly PilotRepository $pilots,
        private readonly ShipTreeService $shipTree
    ) {}

    /** Public profile: /pilot/{id} */
    public function show(int $pilotId): void
    {
        $pilot = $this->visiblePilot($pilotId);
        if ($pilot === null) {
            return;
        }

        echo $this->twig->render('pages/profile.twig', [
            'pilot'           => $pilot,
            'selections'      => $this->pilots->getDisplaySelections($pilot['id']),
            'assets'          => $this->pilots->getDisplayAssets($pilot['id']),
            'displayed_titles' => $this->pilots->getDisplayedTitles($pilot['id']),
            'shiptree'        => $this->publishedShipTree($pilot['id']),
            'is_own'          => ($_SESSION['pilot_id'] ?? null) === $pilot['id'],
        ]);
    }

    /** Public ship tree: /pilot/{id}/ships?faction={factionID} */
    public function shipTree(int $pilotId): void
    {
        $pilot = $this->visiblePilot($pilotId);
        if ($pilot === null) {
            return;
        }

        $published = $this->publishedShipTree($pilotId);
        $factionId = (int) ($_GET['faction'] ?? 0);
        if (!isset($published['factions'][$factionId])) {
            $factionId = array_key_first($published['factions']);
        }

        echo $this->twig->render('pages/shiptree.twig', [
            'pilot'      => $pilot,
            'factions'   => $published['factions'],
            'faction'    => $factionId !== null ? $published['factions'][$factionId] : null,
            'layout'     => (new ShipTreeLayout($published['levels']))
                ->build($factionId !== null ? $this->shipTree->getTree($factionId) : []),
            'levels'     => $published['levels'],
            'is_own'     => ($_SESSION['pilot_id'] ?? null) === $pilot['id'],
        ]);
    }

    /** The pilot if they exist and the viewer may see them; otherwise renders 404/403 and returns null. */
    private function visiblePilot(int $pilotId): ?array
    {
        $pilot = $this->pilots->findById($pilotId);
        if ($pilot === null) {
            http_response_code(404);
            echo $this->twig->render('pages/404.twig', ['search' => (string) $pilotId]);
            return null;
        }

        if (!$pilot['is_public'] && ($_SESSION['pilot_id'] ?? null) !== $pilot['id']) {
            http_response_code(403);
            echo $this->twig->render('pages/403.twig');
            return null;
        }
        return $pilot;
    }

    /** Factions the pilot published, with summaries: ['factions' => [factionID => faction], 'levels' => [typeID => level]] */
    private function publishedShipTree(int $pilotId): array
    {
        $factionIds = array_flip($this->pilots->getShipTreeFactionIds($pilotId));
        if ($factionIds === []) {
            return ['factions' => [], 'levels' => []];
        }

        $levels   = $this->pilots->getShipTreeLevels($pilotId);
        $summary  = $this->shipTree->summarise($levels);
        $factions = [];
        foreach ($this->shipTree->getFactions() as $faction) {
            if (!isset($factionIds[$faction['faction_id']])) continue;
            $factions[$faction['faction_id']] = $faction + $summary[$faction['faction_id']];
        }
        return ['factions' => $factions, 'levels' => $levels];
    }

    /** Dashboard: /dashboard (requires auth) */
    public function dashboard(): void
    {
        $pilotId = $_SESSION['pilot_id'] ?? null;
        if ($pilotId === null) {
            header('Location: /');
            exit;
        }

        $pilot = $this->pilots->findById($pilotId);

        $skills = $_SESSION['fetched']['skills'] ?? null;

        echo $this->twig->render('pages/dashboard.twig', [
            'shiptree_factions' => $this->shipTree->getFactions(),
            'shiptree_summary'  => isset($skills['flyable'])
                ? $this->shipTree->summarise(ShipTreeService::sessionLevels($skills))
                : null,
            'pilot'         => $pilot,
            'selections'    => $this->pilots->getDisplaySelections($pilotId),
            'titles'        => $this->pilots->getPilotTitles($pilotId),
            'saved'         => isset($_GET['saved']),
            'title_fetched' => isset($_GET['title_fetched']),
            'error'         => $_GET['error'] ?? null,
        ]);
    }

    /** POST /dashboard/visibility — toggle public/private */
    public function updateVisibility(): void
    {
        $pilotId = $_SESSION['pilot_id'] ?? null;
        if ($pilotId === null) {
            header('Location: /');
            exit;
        }

        $pilot = $this->pilots->findById($pilotId);
        $this->pilots->setPublic($pilotId, !$pilot['is_public']);

        header('Location: /dashboard');
        exit;
    }

    /** POST /dashboard/titles — save which title to display */
    public function saveTitleSelection(): void
    {
        $pilotId = $_SESSION['pilot_id'] ?? null;
        if ($pilotId === null) {
            header('Location: /');
            exit;
        }

        $submitted = (array) ($_POST['display_title'] ?? []);
        $owned     = array_column($this->pilots->getPilotTitles($pilotId), 'title_id');
        $titleIds  = array_values(array_filter($submitted, fn($id) => in_array($id, $owned, true)));

        $this->pilots->saveDisplayedTitles($pilotId, $titleIds);
        header('Location: /dashboard?saved=1');
        exit;
    }

    /** POST /dashboard/fetch-title — pull current title from ESI and store it */
    public function fetchTitle(): void
    {
        $pilotId = $_SESSION['pilot_id'] ?? null;
        if ($pilotId === null) {
            header('Location: /');
            exit;
        }

        try {
            $esi = new \App\Service\EsiService(new \Monolog\Logger('esi'));
            $this->pilots->syncPublicData($pilotId, $esi);
        } catch (\Throwable) {
            header('Location: /dashboard?error=esi_failed');
            exit;
        }

        header('Location: /dashboard?title_fetched=1');
        exit;
    }

    /** POST /dashboard/display — save skill/cert/mastery display selections */
    public function saveDisplaySelections(): void
    {
        $pilotId = $_SESSION['pilot_id'] ?? null;
        if ($pilotId === null) {
            header('Location: /');
            exit;
        }

        // Each part is saved only when its data is loaded in this session. Anything not loaded keeps
        // what was published before: its checkboxes are disabled, so absence from POST means
        // "no data to check against", not "hide it".
        $fetched = $_SESSION['fetched']['skills'] ?? null;
        if ($fetched !== null) {
            $this->saveSkillSelections($pilotId, $fetched);
        }

        $walletFetched = $_SESSION['fetched']['wallet'] ?? null;
        if ($walletFetched !== null) {
            $isk = ($_POST['show_isk'] ?? '') === '1' ? (float) $walletFetched['balance'] : null;
            $this->pilots->saveDisplayIsk($pilotId, $isk);
        }

        $assetsFetched = $_SESSION['fetched']['assets'] ?? null;
        if ($assetsFetched !== null) {
            $value = ($_POST['show_assets'] ?? '') === '1' ? (float) $assetsFetched['total_value'] : null;
            $this->pilots->saveAssetsValue($pilotId, $value);

            // Asset display selections — validate against session
            $sessionTypes = array_column($assetsFetched['all_types'], null, 'type_id');
            $assets       = [];
            foreach ($_POST as $key => $postValue) {
                if (!str_starts_with($key, 'asset_') || $postValue !== '1') continue;
                $typeId = (int) substr($key, 6);
                if (!isset($sessionTypes[$typeId])) continue;
                $assets[] = [$typeId, $sessionTypes[$typeId]['quantity']];
            }
            $this->pilots->saveDisplayAssets($pilotId, $assets);
        }

        header('Location: /dashboard?saved=1');
        exit;
    }

    /** Skills, certificates, masteries, ship trees and SP: everything that needs skills loaded. */
    private function saveSkillSelections(int $pilotId, array $fetched): void
    {
        // Build indexed lookups from session for validation
        $sessionSkills    = array_column($fetched['skills'], null, 'skill_id');
        $sessionCerts     = $fetched['certs'];      // [certID => {level, name}]
        $sessionMasteries = $fetched['masteries'];  // [typeID => {level, name}]

        $skills = $certs = $masteries = [];
        foreach ($_POST as $key => $value) {
            if ($value !== '1') continue;
            if (str_starts_with($key, 'skill_')) {
                $skillId = (int) substr($key, 6);
                if (isset($sessionSkills[$skillId])) {      // not in their session — reject
                    $skills[] = [$skillId, $sessionSkills[$skillId]['active_skill_level']];
                }
            } elseif (str_starts_with($key, 'cert_')) {
                $certId = (int) substr($key, 5);
                if (isset($sessionCerts[$certId])) {        // not completed — reject
                    $certs[] = [$certId, $sessionCerts[$certId]['level']];
                }
            } elseif (str_starts_with($key, 'mastery_')) {
                $typeId = (int) substr($key, 8);
                if (isset($sessionMasteries[$typeId])) {    // not completed — reject
                    $masteries[] = [$typeId, $sessionMasteries[$typeId]['level']];
                }
            }
        }

        $this->pilots->saveDisplaySkills($pilotId, $skills);
        $this->pilots->saveDisplayCerts($pilotId, $certs);
        $this->pilots->saveDisplayMasteries($pilotId, $masteries);

        // Ship tree — factions validated against the SDE, levels taken from session.
        // Sessions fetched before ship tree support lack 'flyable'; keep what's saved.
        if (isset($fetched['flyable'])) {
            $levels    = ShipTreeService::sessionLevels($fetched);
            $byFaction = $this->shipTree->getShipIdsByFaction();
            $factions  = [];
            $ships     = [];
            foreach ($_POST as $key => $value) {
                if (!str_starts_with($key, 'shiptree_') || $value !== '1') continue;
                $factionId = (int) substr($key, 9);
                if (!isset($byFaction[$factionId])) continue;
                $factions[] = $factionId;
                foreach ($byFaction[$factionId] as $typeId) {
                    if (isset($levels[$typeId])) {
                        $ships[] = [$typeId, $levels[$typeId]];
                    }
                }
            }
            $this->pilots->saveShipTree($pilotId, $factions, $ships);
        }

        $sp = ($_POST['show_sp'] ?? '') === '1' && isset($fetched['total_sp']) ? (float) $fetched['total_sp'] : null;
        $this->pilots->saveDisplaySp($pilotId, $sp);
    }
}
