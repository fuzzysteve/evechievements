<?php
declare(strict_types=1);
namespace App\Controller;

use App\Service\ShipInfoService;
use App\Service\ShipTreeService;
use Twig\Environment;

/**
 * Ship pages. The page itself is generic; a logged-in viewer additionally sees what THEY are
 * missing, worked out from their own session's skills. Never stored, never shown to anyone else.
 */
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

        $flySkills    = $this->shipTree->getFlyRequirements($typeId);
        $requirements = $this->shipTree->getMasteryRequirements($typeId);

        echo $this->twig->render('pages/ship.twig', [
            'mine'         => $this->viewerProgress($requirements, $flySkills),
            'ship'         => $ship,
            'level'        => $level,
            'active'       => $tab ?? "level-{$level}",
            'attributes'   => $this->shipInfo->getAttributes($typeId),
            'bonuses'      => $this->shipInfo->getBonuses($typeId),
            'fly_skills'   => $flySkills,
            'requirements' => $requirements,
        ]);
    }

    /**
     * The logged-in viewer's progress on this ship, from their own session only.
     *
     * @return array|null  null when nobody is logged in; ['loaded' => false] when they haven't loaded
     *                     skills; otherwise ['loaded', 'skills' => [skillID => level], 'can_fly',
     *                     'mastery' => 0-5, 'missing' => [1..5 => [['skill_id', 'name', 'have', 'need'], ...]]]
     */
    private function viewerProgress(array $requirements, array $flySkills): ?array
    {
        if (empty($_SESSION['pilot_id'])) {
            return null;
        }
        $fetched = $_SESSION['fetched']['skills']['skills'] ?? null;
        if ($fetched === null) {
            return ['loaded' => false];
        }
        $skills = array_column($fetched, 'active_skill_level', 'skill_id');

        // Skills short of what's needed, highest requirement per skill. Flying the ship is part
        // of every mastery level, so its skills count towards each level too.
        $shortfall = function (array $required) use ($skills): array {
            $need = [];
            foreach ($required as $skill) {
                $id = $skill['skill_id'];
                if (($skills[$id] ?? 0) < $skill['level'] && $skill['level'] > ($need[$id]['need'] ?? 0)) {
                    $need[$id] = ['skill_id' => $id, 'name' => $skill['name'],
                                  'have' => $skills[$id] ?? 0, 'need' => $skill['level']];
                }
            }
            uasort($need, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
            return array_values($need);
        };

        $flyMissing = $shortfall($flySkills);
        $missing    = [];
        $mastery    = 0;
        foreach ($requirements as $level => $certs) {
            $certSkills      = array_merge([], ...array_column($certs, 'skills'));
            $missing[$level] = $shortfall(array_merge($certSkills, $flySkills));
            if ($missing[$level] === [] && $mastery === $level - 1) {
                $mastery = $level;
            }
        }

        return [
            'loaded'  => true,
            'skills'  => $skills,
            'can_fly' => $flyMissing === [],
            'mastery' => $mastery,
            'missing' => $missing,
        ];
    }
}
