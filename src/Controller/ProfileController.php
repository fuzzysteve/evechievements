<?php
declare(strict_types=1);
namespace App\Controller;

use App\Model\PilotRepository;
use App\Service\TrophyService;
use App\Config\Database;
use Twig\Environment;

final class ProfileController
{
    public function __construct(
        private readonly Environment     $twig,
        private readonly PilotRepository $pilots,
        private readonly TrophyService   $trophies
    ) {}

    /** Public profile: /pilot/{name} */
    public function show(string $name): void
    {
        $pilot = $this->pilots->findById(intval($name));
        if ($pilot === null) {
            http_response_code(404);
            echo $this->twig->render('pages/404.twig', ['search' => $name]);
            return;
        }

        if (!$pilot['is_public'] && ($_SESSION['pilot_id'] ?? null) !== $pilot['id']) {
            http_response_code(403);
            echo $this->twig->render('pages/403.twig');
            return;
        }

        echo $this->twig->render('pages/profile.twig', [
            'pilot'      => $pilot,
            'selections' => $this->pilots->getDisplaySelections($pilot['id']),
            'assets'     => $this->pilots->getDisplayAssets($pilot['id']),
            'is_own'     => ($_SESSION['pilot_id'] ?? null) === $pilot['id'],
        ]);
    }

    /** Dashboard: /dashboard (requires auth) */
    public function dashboard(): void
    {
        $pilotId = $_SESSION['pilot_id'] ?? null;
        if ($pilotId === null) {
            header('Location: /');
            exit;
        }

        $pilot      = $this->pilots->findById($pilotId);
        $selections = $this->pilots->getDisplaySelections($pilotId);

        echo $this->twig->render('pages/dashboard.twig', [
            'pilot'      => $pilot,
            'selections' => $selections,
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

    /** POST /dashboard/display — save skill/cert/mastery display selections */
    public function saveDisplaySelections(): void
    {
        $pilotId = $_SESSION['pilot_id'] ?? null;
        if ($pilotId === null) {
            header('Location: /');
            exit;
        }

        $fetched = $_SESSION['fetched']['skills'] ?? null;
        if ($fetched === null) {
            header('Location: /dashboard?error=no_skill_data');
            exit;
        }

        // Build indexed lookups from session for validation
        $sessionSkills    = array_column($fetched['skills'], null, 'skill_id');
        $sessionCerts     = $fetched['certs'];      // [certID => {level, name}]
        $sessionMasteries = $fetched['masteries'];  // [typeID => {level, name}]

        // Skills — validate each submitted ID exists in session
        $skills = [];
        foreach ($_POST as $key => $value) {
            if (!str_starts_with($key, 'skill_') || $value !== '1') continue;
            $skillId = (int) substr($key, 6);
            if (!isset($sessionSkills[$skillId])) continue; // not in their session — reject
            $skills[] = [$skillId, $sessionSkills[$skillId]['active_skill_level']];
        }

        // Certs — validate against session
        $certs = [];
        foreach ($_POST as $key => $value) {
            if (!str_starts_with($key, 'cert_') || $value !== '1') continue;
            $certId = (int) substr($key, 5);
            if (!isset($sessionCerts[$certId])) continue; // not completed — reject
            $certs[] = [$certId, $sessionCerts[$certId]['level']];
        }

        // Masteries — validate against session
        $masteries = [];
        foreach ($_POST as $key => $value) {
            if (!str_starts_with($key, 'mastery_') || $value !== '1') continue;
            $typeId = (int) substr($key, 8);
            if (!isset($sessionMasteries[$typeId])) continue; // not completed — reject
            $masteries[] = [$typeId, $sessionMasteries[$typeId]['level']];
        }

        $this->pilots->saveDisplaySkills($pilotId, $skills);
        $this->pilots->saveDisplayCerts($pilotId, $certs);
        $this->pilots->saveDisplayMasteries($pilotId, $masteries);

        // SP
        $sp = null;
        if (($_POST['show_sp'] ?? '') === '1' && isset($fetched['total_sp'])) {
            $sp = (float) $fetched['total_sp'];
        }

        // ISK
        $isk = null;
        if (($_POST['show_isk'] ?? '') === '1') {
            $walletFetched = $_SESSION['fetched']['wallet'] ?? null;
            if ($walletFetched !== null) {
                $isk = (float) $walletFetched['balance'];
            }
        }

        $this->pilots->saveDisplaySpIsk($pilotId, $sp, $isk);

        // Assets value
        $assetsFetched = $_SESSION['fetched']['assets'] ?? null;
        $assetsValue   = null;
        if (($_POST['show_assets'] ?? '') === '1' && $assetsFetched !== null) {
            $assetsValue = (float) $assetsFetched['total_value'];
        }
        $this->pilots->saveAssetsValue($pilotId, $assetsValue);

        // Asset display selections — validate against session
        $assets = [];
        if ($assetsFetched !== null) {
            $sessionTypes = array_column($assetsFetched['all_types'], null, 'type_id');
            foreach ($_POST as $key => $value) {
                if (!str_starts_with($key, 'asset_') || $value !== '1') continue;
                $typeId = (int) substr($key, 6);
                if (!isset($sessionTypes[$typeId])) continue;
                $assets[] = [$typeId, $sessionTypes[$typeId]['quantity']];
            }
        }
        $this->pilots->saveDisplayAssets($pilotId, $assets);

        header('Location: /dashboard?saved=1');
        exit;
    }
}
