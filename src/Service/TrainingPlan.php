<?php
declare(strict_types=1);
namespace App\Service;

/**
 * What a pilot still has to train for a set of skill requirements, and roughly how long it takes.
 * Built from the viewer's OWN session skills; results are shown only to them and never stored.
 *
 * Gaps include the prerequisites of skills not yet started (a started skill's prerequisites are
 * already met), all the way down. Skill points already earned towards a level are subtracted, so
 * part-trained skills cost less. All SDE data is loaded up front in batches by forSession().
 */
final class TrainingPlan
{
    /**
     * @param array $levels  [skillID => trained level]
     * @param array $points  [skillID => skill points in skill]
     * @param array $graph   SkillService::prerequisiteGraph() for every skill that may be asked about
     * @param array $details SkillService::details() for every skill in $graph
     */
    private function __construct(
        private readonly array $levels,
        private readonly array $points,
        private readonly array $graph,
        private readonly array $details,
    ) {}

    /**
     * @param array $sessionSkills $_SESSION['fetched']['skills']['skills']
     * @param int[] $skillIds      every skill that requirements passed to gaps() may name
     */
    public static function forSession(SkillService $skills, array $sessionSkills, array $skillIds): self
    {
        $graph = $skills->prerequisiteGraph($skillIds);
        return new self(
            array_column($sessionSkills, 'active_skill_level', 'skill_id'),
            array_column($sessionSkills, 'skillpoints_in_skill', 'skill_id'),
            $graph,
            $skills->details(array_keys($graph)),
        );
    }

    /**
     * Skills short of their required level, the mastery's/ship's own skills first, then prerequisites.
     *
     * @param  array $required [['skill_id' => int, 'level' => int, ...], ...]
     * @return array [['skill_id', 'name', 'have', 'need', 'minutes', 'prerequisite'], ...]
     */
    public function gaps(array $required): array
    {
        $need = [];
        foreach ($required as $skill) {
            $need[$skill['skill_id']] = max($need[$skill['skill_id']] ?? 0, $skill['level']);
        }
        $direct = $need;

        $queue = array_keys($need);
        while ($queue !== []) {
            $id = array_pop($queue);
            if (($this->levels[$id] ?? 0) > 0) continue;
            foreach ($this->graph[$id] ?? [] as $pre => $level) {
                if ($level > ($need[$pre] ?? 0)) {
                    $need[$pre] = $level;
                    $queue[]    = $pre;
                }
            }
        }

        $gaps = [];
        foreach ($need as $id => $level) {
            $have = $this->levels[$id] ?? 0;
            if ($have >= $level) continue;
            $sp     = SkillService::spPerLevel($this->details[$id]['rank'] ?? 1);
            $earned = max((float) ($this->points[$id] ?? 0), $have > 0 ? $sp[$have] : 0);
            $gaps[] = [
                'skill_id'     => $id,
                'name'         => $this->details[$id]['name'] ?? "Skill #{$id}",
                'have'         => $have,
                'need'         => $level,
                'minutes'      => SkillService::minutesFor($sp[$level] - $earned),
                'prerequisite' => !isset($direct[$id]),
            ];
        }
        usort($gaps, fn($a, $b) => $a['prerequisite'] <=> $b['prerequisite'] ?: strnatcasecmp($a['name'], $b['name']));
        return $gaps;
    }

    /** Total approximate minutes to meet $required (see gaps()). */
    public function minutes(array $required): int
    {
        return array_sum(array_column($this->gaps($required), 'minutes'));
    }
}
