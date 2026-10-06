<?php
declare(strict_types=1);
namespace App\Controller;

use App\Service\ShipTreeService;
use Twig\Environment;

/** Generic, pilot-independent ship pages. */
final class ShipController
{
    public function __construct(
        private readonly Environment     $twig,
        private readonly ShipTreeService $shipTree
    ) {}

    /** Mastery requirements: /ship/{typeID}?level={1-5} */
    public function show(int $typeId): void
    {
        $ship = $this->shipTree->getShip($typeId);
        if ($ship === null) {
            http_response_code(404);
            echo $this->twig->render('pages/404.twig', ['search' => (string) $typeId]);
            return;
        }

        $level = (int) ($_GET['level'] ?? 1);
        if ($level < 1 || $level > 5) {
            $level = 1;
        }

        echo $this->twig->render('pages/ship.twig', [
            'ship'         => $ship,
            'level'        => $level,
            'fly_skills'   => $this->shipTree->getFlyRequirements($typeId),
            'requirements' => $this->shipTree->getMasteryRequirements($typeId),
        ]);
    }
}
