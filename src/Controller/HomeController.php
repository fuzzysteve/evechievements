<?php
declare(strict_types=1);
namespace App\Controller;

use App\Service\TrophyService;
use App\Model\PilotRepository;
use App\Config\Database;
use Twig\Environment;

final class HomeController
{
    public function __construct(
        private readonly Environment      $twig,
        private readonly TrophyService    $trophyService,
        private readonly PilotRepository  $pilots
    ) {}

    public function index(): void
    {
        $db = Database::connect();

        $recentTrophies = $this->trophyService->getRecentPublicTrophies(8);
        $totalPilots    = (int) $db->query('SELECT COUNT(*) FROM pilots')->fetchColumn();
        $totalTrophies  = (int) $db->query('SELECT COUNT(*) FROM pilot_trophies')->fetchColumn();

        echo $this->twig->render('pages/home.twig', [
            'recent_trophies' => $recentTrophies,
            'total_pilots'    => $totalPilots,
            'total_trophies'  => $totalTrophies,
            'online_count'    => 0, // fetched from ESI server status (optional)
        ]);
    }
}
