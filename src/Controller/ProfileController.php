<?php
declare(strict_types=1);
namespace App\Controller;

use App\Model\PilotRepository;
use App\Service\EsiService;
use App\Service\TrophyService;
use App\Config\Database;
use Twig\Environment;

final class ProfileController
{
    public function __construct(
        private readonly Environment     $twig,
        private readonly PilotRepository $pilots,
	private readonly TrophyService   $trophies,
    ) {}

    /** Public profile: /pilot/{name} */
    public function show(string $id): void
    {
        $pilot = $this->pilots->findById(intval($id));
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

        $db      = Database::connect();
	$stats   = $this->pilots->getStats($pilot['id']);


        // Skill queue (top 5)

        // Corp history

        echo $this->twig->render('pages/profile.twig', [
            'pilot'        => $pilot,
            'stats'        => $stats,
            'is_own'       => ($_SESSION['pilot_id'] ?? null) === $pilot['id'],
        ]);
    }

    /** Dashboard: /dashboard (requires auth) */
    public function dashboard(): void
    {   
	$esi = new EsiService(new \Monolog\Logger('esi'));    
	$pilotId = $_SESSION['pilot_id'] ?? null;
        if ($pilotId === null) {
            header('Location: /');
            exit;
        }

	$pilot = $this->pilots->findById($pilotId);

        if ($pilot === null) {
	    $pilot = $this->pilots->createById($pilotId,$esi);
        }



        $stats   = $this->pilots->getStats($pilotId);

        echo $this->twig->render('pages/dashboard.twig', [
            'pilot'    => $pilot,
            'stats'    => $stats,
        ]);
    }
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
}
