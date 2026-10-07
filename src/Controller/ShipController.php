<?php
declare(strict_types=1);
namespace App\Controller;

use App\Service\ShipInfoService;
use App\Service\ShipTreeService;
use Twig\Environment;

/** Generic, pilot-independent ship pages. */
final class ShipController
{
    public function __construct(
        private readonly Environment     $twig,
        private readonly ShipTreeService $shipTree,
        private readonly ShipInfoService $shipInfo
    ) {}

    /** Ship page: /ship/{typeID}, tabs ?level={1-5} (mastery), ?tab=attributes, ?tab=bonuses */
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
        $tab = in_array($_GET['tab'] ?? '', ['attributes', 'bonuses'], true) ? $_GET['tab'] : null;

        echo $this->twig->render('pages/ship.twig', [
            'ship'         => $ship,
            'level'        => $level,
            'active'       => $tab ?? "level-{$level}",
            'attributes'   => $this->shipInfo->getAttributes($typeId),
            'bonuses'      => $this->shipInfo->getBonuses($typeId),
            'fly_skills'   => $this->shipTree->getFlyRequirements($typeId),
            'requirements' => $this->shipTree->getMasteryRequirements($typeId),
        ]);
    }
}
