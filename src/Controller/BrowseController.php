<?php
declare(strict_types=1);
namespace App\Controller;

use App\Model\PilotRepository;
use Twig\Environment;

final class BrowseController
{
    public function __construct(
        private readonly Environment     $twig,
        private readonly PilotRepository $pilots
    ) {}

    public function index(): void
    {
        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 24;

        $pilots = $this->pilots->getPublicPilots($page, $perPage);
        $total  = $this->pilots->countPublic();
        $pages  = (int) ceil($total / $perPage);

        echo $this->twig->render('pages/browse.twig', [
            'pilots'       => $pilots,
            'current_page' => $page,
            'total_pages'  => $pages,
            'total_pilots' => $total,
        ]);
    }
}
