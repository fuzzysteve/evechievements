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

        $totalPilots    = (int) $db->query('SELECT COUNT(*) FROM pilots')->fetchColumn();

        echo $this->twig->render('pages/home.twig', [
            'total_pilots'    => $totalPilots,
            'online_count'    => 0, // fetched from ESI server status (optional)
        ]);
    }
}
