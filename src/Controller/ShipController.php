<?php
declare(strict_types=1);
namespace App\Controller;

use App\Service\ShipInfoService;
use App\Service\ShipTreeLayout;
use App\Service\ShipTreeService;
use App\Service\SkillService;
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
            'layout'    => (new ShipTreeLayout($levels ?? []))->build($this->shipTree->getTree($factionId)),
        ]);
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
        $points = array_column($fetched, 'skillpoints_in_skill', 'skill_id');

        // Every skill any level needs, then (batched) their prerequisite chains, names and ranks
        $needed = array_column($flySkills, 'skill_id');
        foreach ($requirements as $certs) {
            foreach ($certs as $cert) {
                array_push($needed, ...array_column($cert['skills'], 'skill_id'));
            }
        }
        $graph   = $this->skills->prerequisiteGraph($needed);
        $details = $this->skills->details(array_keys($graph));

        // Required levels for a set of skills, plus the prerequisites of any not yet started
        // (a started skill's prerequisites are already met), all the way down
        $withPrerequisites = function (array $required) use ($graph, $skills): array {
            $need  = [];
            foreach ($required as $skill) {
                $need[$skill['skill_id']] = max($need[$skill['skill_id']] ?? 0, $skill['level']);
            }
            $direct = $need;
            $queue  = array_keys($need);
            while ($queue !== []) {
                $id = array_pop($queue);
                if (($skills[$id] ?? 0) > 0) continue;
                foreach ($graph[$id] ?? [] as $pre => $level) {
                    if ($level > ($need[$pre] ?? 0)) {
                        $need[$pre] = $level;
                        $queue[]    = $pre;
                    }
                }
            }
            return [$need, $direct];
        };

        // Skills short of their required level, with the time to close each gap
        $shortfall = function (array $required) use ($withPrerequisites, $skills, $points, $details): array {
            [$need, $direct] = $withPrerequisites($required);
            $gaps = [];
            foreach ($need as $id => $level) {
                $have = $skills[$id] ?? 0;
                if ($have >= $level) continue;
                $sp      = SkillService::spPerLevel($details[$id]['rank'] ?? 1);
                $earned  = max((float) ($points[$id] ?? 0), $have > 0 ? $sp[$have] : 0);
                $gaps[]  = [
                    'skill_id'     => $id,
                    'name'         => $details[$id]['name'] ?? "Skill #{$id}",
                    'have'         => $have,
                    'need'         => $level,
                    'minutes'      => SkillService::minutesFor($sp[$level] - $earned),
                    'prerequisite' => !isset($direct[$id]),
                ];
            }
            // The mastery's own skills first, prerequisites after; each group by name
            usort($gaps, fn($a, $b) => $a['prerequisite'] <=> $b['prerequisite'] ?: strnatcasecmp($a['name'], $b['name']));
            return $gaps;
        };

        $flyMissing = $shortfall($flySkills);
        $missing = [];
        $minutes = [];
        $mastery = 0;
        foreach ($requirements as $level => $certs) {
            $certSkills      = array_merge([], ...array_column($certs, 'skills'));
            $missing[$level] = $shortfall(array_merge($certSkills, $flySkills));
            $minutes[$level] = array_sum(array_column($missing[$level], 'minutes'));
            if ($missing[$level] === [] && $mastery === $level - 1) {
                $mastery = $level;
            }
        }

        return [
            'loaded'  => true,
            'skills'  => $skills,
            'can_fly'     => $flyMissing === [],
            'fly_missing' => $flyMissing,
            'fly_minutes' => array_sum(array_column($flyMissing, 'minutes')),
            'mastery' => $mastery,
            'missing' => $missing,
            'minutes' => $minutes,
        ];
    }
}
