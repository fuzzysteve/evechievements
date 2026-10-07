<?php
declare(strict_types=1);
namespace App\Controller;

use App\Config\Database;
use Twig\Environment;

final class HomeController
{
    public function __construct(
        private readonly Environment $twig
    ) {}

    public function index(): void
    {
        $db = Database::connect();

        $totalPilots    = (int) $db->query('SELECT COUNT(*) FROM pilots')->fetchColumn();
        $publicPilots   = (int) $db->query('SELECT COUNT(*) FROM pilots WHERE is_public = true')->fetchColumn();

        echo $this->twig->render('pages/home.twig', [
            'total_pilots'    => $totalPilots,
            'public_pilots'   => $publicPilots,
            'error'           => $_GET['error'] ?? null,
            'online_count'    => 0, // fetched from ESI server status (optional)
        ]);
    }
}
