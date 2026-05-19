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
        $pilot = $this->pilots->findByName($name);
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
        $trophys = $this->trophies->getPilotTrophies($pilot['id']);

        // Skill queue (top 5)
        $queue = $db->prepare(
            'SELECT * FROM skill_queue WHERE pilot_id = ? ORDER BY queue_position ASC LIMIT 5'
        );
        $queue->execute([$pilot['id']]);

        // Corp history
        $corps = $db->prepare(
            'SELECT * FROM corp_history WHERE pilot_id = ? ORDER BY start_date DESC LIMIT 10'
        );
        $corps->execute([$pilot['id']]);

        echo $this->twig->render('pages/profile.twig', [
            'pilot'        => $pilot,
            'stats'        => $stats,
            'trophies'     => $trophys,
            'skill_queue'  => $queue->fetchAll(),
            'corp_history' => $corps->fetchAll(),
            'is_own'       => ($_SESSION['pilot_id'] ?? null) === $pilot['id'],
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

        $pilot   = $this->pilots->findById($pilotId);
        $stats   = $this->pilots->getStats($pilotId);
        $trophys = $this->trophies->getPilotTrophies($pilotId);

        echo $this->twig->render('pages/dashboard.twig', [
            'pilot'    => $pilot,
            'stats'    => $stats,
            'trophies' => $trophys,
        ]);
    }
}
