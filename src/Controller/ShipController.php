<?php
declare(strict_types=1);
namespace App\Controller;

use App\Service\ShipInfoService;
use App\Service\ShipTreeLayout;
use App\Service\ShipTreeService;
use App\Service\SkillService;
use App\Service\TrainingPlan;
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
        private readonly ShipInfoService $shipInfo,
        private readonly SkillService    $skills
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
     * Every faction's ship tree: /ships?faction={factionID}. Generic for visitors; a logged-in viewer
     * with skills loaded sees it with their own flyable/mastery state (from their session only, shown
     * only to them, never stored) unless they pick ?view=all.
     */
    public function trees(): void
    {
        $factions  = array_column($this->shipTree->getFactions(), null, 'faction_id');
        $factionId = (int) ($_GET['faction'] ?? 0);
        if (!isset($factions[$factionId])) {
            $factionId = array_key_first($factions);
        }

        $loggedIn = !empty($_SESSION['pilot_id']);
        $skills   = $_SESSION['fetched']['skills'] ?? null;
        $hasTree  = $loggedIn && isset($skills['flyable']);
        $viewAll  = ($_GET['view'] ?? '') === 'all';
        $levels   = $hasTree && !$viewAll ? ShipTreeService::sessionLevels($skills) : null;
        $tree     = $this->shipTree->getTree($factionId);

        echo $this->twig->render('pages/ships.twig', [
            'factions'  => $factions,
            'faction'   => $factions[$factionId],
            'levels'    => $levels,
            'summary'   => $levels !== null ? $this->shipTree->summarise($levels) : null,
            'viewer'    => !$loggedIn ? null : ($hasTree ? 'ready' : ($skills === null ? 'no_skills' : 'stale')),
            'view_all'  => $viewAll,
            // Where the log-in / load-skills buttons bring the viewer back to (their own tree)
            'return_to' => '/ships?faction=' . $factionId,
            'error'     => $_GET['error'] ?? null,
            'layout'    => (new ShipTreeLayout($levels ?? []))->build($tree),
            'fly_times' => $levels !== null ? $this->flyTimes($tree, $levels, $skills['skills']) : null,
        ]);
    }

    /**
     * Approximate minutes until the viewer can fly each ship in the tree they can't fly yet, from their
     * own session (shown only to them). Batched: one query for every ship's requirements, then the
     * prerequisite chains and skill ranks in a few more.
     *
     * @return array [typeID => minutes]
     */
    private function flyTimes(array $tree, array $levels, array $sessionSkills): array
    {
        $locked = [];
        foreach ($tree as $root) {
            foreach (array_merge([$root], $root['branches']) as $node) {
                foreach ($node['ships'] as $ship) {
                    if (!isset($levels[$ship['type_id']])) {
                        $locked[] = $ship['type_id'];
                    }
                }
            }
        }
        $requirements = $this->shipTree->getFlyRequirementsFor($locked);
        if ($requirements === []) {
            return [];
        }
        $skillIds = array_merge([], ...array_map(fn($reqs) => array_column($reqs, 'skill_id'), array_values($requirements)));
        $plan     = TrainingPlan::forSession($this->skills, $sessionSkills, $skillIds);

        $times = [];
        foreach ($requirements as $typeId => $required) {
            // 0 = nothing left to train: the session's flyable list is older than its skills; say nothing
            if ($minutes = $plan->minutes($required)) {
                $times[$typeId] = $minutes;
            }
        }
        return $times;
    }

    /**
     * The logged-in viewer's progress on this ship, from their own session only.
     *
     * For each mastery level they don't have: the skills they're short of (mastery certificates plus
     * the skills to fly the ship), any prerequisites of skills they haven't started, and an approximate
     * training time. Skill points already earned towards a level count, so part-trained skills and
     * partly-met masteries cost less. Everything is looked up in a few batched queries.
     *
     * @return array|null  null when nobody is logged in; ['loaded' => false] when they haven't loaded
     *                     skills; otherwise ['loaded', 'skills' => [skillID => level], 'can_fly',
     *                     'mastery' => 0-5, 'missing' => [1..5 => [['skill_id', 'name', 'have', 'need',
     *                     'minutes', 'prerequisite'], ...]], 'minutes' => [1..5 => int],
     *                     'fly_missing' => [same shape as a missing level], 'fly_minutes' => int]
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

        // Every skill any level needs; TrainingPlan loads their prerequisite chains, names and ranks in batches
        $needed = array_column($flySkills, 'skill_id');
        foreach ($requirements as $certs) {
            foreach ($certs as $cert) {
                array_push($needed, ...array_column($cert['skills'], 'skill_id'));
            }
        }
        $plan = TrainingPlan::forSession($this->skills, $fetched, $needed);

        $flyMissing = $plan->gaps($flySkills);
        $missing    = [];
        $minutes    = [];
        $mastery    = 0;
        foreach ($requirements as $level => $certs) {
            $certSkills      = array_merge([], ...array_column($certs, 'skills'));
            $missing[$level] = $plan->gaps(array_merge($certSkills, $flySkills));
            $minutes[$level] = array_sum(array_column($missing[$level], 'minutes'));
            if ($missing[$level] === [] && $mastery === $level - 1) {
                $mastery = $level;
            }
        }

        return [
            'loaded'      => true,
            'skills'      => $skills,
            'can_fly'     => $flyMissing === [],
            'fly_missing' => $flyMissing,
            'fly_minutes' => array_sum(array_column($flyMissing, 'minutes')),
            'mastery'     => $mastery,
            'missing'     => $missing,
            'minutes'     => $minutes,
        ];
    }
}
