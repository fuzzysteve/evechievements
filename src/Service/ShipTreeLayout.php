<?php
declare(strict_types=1);
namespace App\Service;

/**
 * Positions a faction's ship tree (from ShipTreeService::getTree) in the style of
 * the in-game tree: hulls along horizontal lanes, each with a vertical stem that
 * branches diagonally to its specialisations, and capitals fanned off the end.
 *
 * Lanes: the starting hull (Corvette) sits at the origin. Each chain of hulls that
 * starts from it (Frigate → … → Battleship, Hauler → Freighter) gets its own lane,
 * with any other starting hull (Shuttle) at the head of the second lane. Factions
 * without a starting hull put their unlinked hulls on one lane, smallest first.
 *
 * Everything is computed in pixels so the template can absolutely position boxes and
 * draw connectors in an SVG. The box/tile sizes must match the .tree-box / .tree-tile CSS.
 */
final class ShipTreeLayout
{
    public const TILE_W = 44;   // .tree-tile width
    public const TILE_H = 50;   // .tree-tile height
    public const GAP    = 3;    // gap between tiles
    public const PAD    = 4;    // .tree-box padding
    public const LABEL  = 16;   // .tree-box__label height
    public const COLS   = 3;    // tiles per row

    private const BOX_DX   = 12; // stem to the hull box
    private const DIAG     = 28; // horizontal reach of a diagonal branch
    private const V_GAP    = 10; // between stacked boxes
    private const COL_GAP  = 28; // between columns
    private const ROW_GAP  = 16; // between boxes in a capital row
    private const LANE_GAP = 40; // between lanes
    private const MARGIN   = 24;
    private const ATTACH   = 8;  // where connectors meet a box, below its top
    private const LABEL_CH = 6;  // approx. width of one .tree-box__label character

    private array $levels;
    private array $boxes = [];
    private array $lines = [];
    private array $dots  = [];

    /** @param array $levels [typeID => masteryLevel] for ships the pilot can fly */
    public function __construct(array $levels)
    {
        $this->levels = $levels;
    }

    /** @return array ['width', 'height', 'boxes' => [...], 'lines' => [...], 'dots' => [...]] */
    public function build(array $tree): array
    {
        if ($tree === []) {
            return ['width' => 0, 'height' => 0, 'boxes' => [], 'lines' => [], 'dots' => []];
        }

        $roots    = array_column($tree, null, 'group_id');
        $children = [];
        foreach ($tree as $node) {
            if ($node['after'] !== null) {
                $children[$node['after']][] = $node['group_id'];
            }
        }

        $hasHulls = array_filter($tree, fn($n) => !$n['starter']) !== [];
        $starters = $hasHulls ? array_values(array_filter($tree, fn($n) => $n['starter'])) : [];
        $origin   = $starters[0] ?? null;
        $unlinked = array_values(array_filter($tree, fn($n) => $n['after'] === null && ($origin === null || !$n['starter'])));

        // Lane 0: the first chain from the origin plus any hulls with no link at all.
        // Further chains from the origin get a lane each.
        // A chain gets a lane of its own only if more hulls follow it (Hauler → Freighter);
        // lone hulls (CONCORD's Recon, Flag Cruiser, …) stay on lane 0.
        $fromStart = array_values(array_filter($unlinked, fn($n) => $origin !== null && $n['from_start']));
        $loose     = array_values(array_filter($unlinked, fn($n) => $origin === null || !$n['from_start']));
        $ownLane   = array_values(array_filter(array_slice($fromStart, 1), fn($n) => !empty($children[$n['group_id']])));
        $heads     = [array_merge(
            array_slice($fromStart, 0, 1),
            array_filter(array_slice($fromStart, 1), fn($n) => empty($children[$n['group_id']])),
            $loose
        )];
        foreach ($ownLane as $head) {
            $heads[] = [$head];
        }
        usort($heads[0], fn($a, $b) => $a['mass'] <=> $b['mass']);

        $lanes = [];
        foreach ($heads as $i => $laneHeads) {
            $columns = [];
            if ($i === 0 && $origin !== null) {
                $columns[] = $this->hullColumn($origin, []);
            }
            if ($i === 1) {
                foreach (array_slice($starters, 1) as $starter) {
                    $columns[] = $this->hullColumn($starter, []);
                }
            }
            foreach ($laneHeads as $head) {
                array_push($columns, ...$this->chain($head, $roots, $children));
            }
            $lanes[] = $columns;
        }
        // Spare starters with no second lane to lead go after the origin
        if (count($lanes) === 1 && count($starters) > 1) {
            array_splice($lanes[0], 1, 0, array_map(fn($s) => $this->hullColumn($s, []), array_slice($starters, 1)));
        }

        return $this->place($lanes);
    }

    // ── Lane building ─────────────────────────────────────────────────────

    /** The columns for a chain of hulls starting at $node. */
    private function chain(array $node, array $roots, array $children): array
    {
        $columns = [];
        while ($node !== null) {
            $kids   = array_map(fn($g) => $roots[$g], $children[$node['group_id']] ?? []);
            $inner  = array_values(array_filter($kids, fn($k) => !empty($children[$k['group_id']])));
            $leaves = array_values(array_filter($kids, fn($k) => empty($children[$k['group_id']])));

            // Carry on along the lane with the hull that leads somewhere, or the only hull that follows
            $next = $inner[0] ?? (count($leaves) === 1 ? $leaves[0] : null);
            if ($next !== null && $inner === []) {
                $leaves = [];
            }

            if ($next === null && count($leaves) > 1) {
                $columns[] = $this->hullColumn($node, []);
                $columns[] = ['fan' => $leaves];
            } else {
                $columns[] = $this->hullColumn($node, $leaves);
            }
            // Any further hulls that lead somewhere hang off this one too
            foreach (array_slice($inner, 1) as $extra) {
                $columns[array_key_last($columns)]['extra'][] = $extra;
            }
            $node = $next;
        }
        return $columns;
    }

    /** A hull on a lane: navy/faction group directly above it, other groups branching off the stem. */
    private function hullColumn(array $node, array $leafHulls): array
    {
        $direct = null;
        $diag   = [];
        foreach ($node['branches'] as $branch) {
            if ($direct === null && min(array_column($branch['ships'], 'tech_level')) === 1) {
                $direct = $branch;
            } else {
                $diag[] = $branch;
            }
        }
        return ['base' => $node, 'direct' => $direct, 'diag' => array_merge($diag, $leafHulls)];
    }

    // ── Placement ─────────────────────────────────────────────────────────

    private function place(array $lanes): array
    {
        // Measure every column relative to its lane line (y = 0)
        foreach ($lanes as &$columns) {
            foreach ($columns as &$col) {
                $col += $this->measure($col);
            }
        }
        unset($columns, $col);

        // Lane y positions
        $laneY = [];
        $y     = self::MARGIN;
        foreach ($lanes as $i => $columns) {
            $above     = max(array_column($columns, 'above'));
            $y        += $above;
            $laneY[$i] = $y;
            $y        += max(array_column($columns, 'below')) + self::LANE_GAP;
        }

        // Lane 0 left to right; later lanes spread out under it
        $x     = self::MARGIN;
        $xs    = [];
        foreach ($lanes[0] as $j => $col) {
            $xs[0][$j] = $x;
            $x        += $col['width'] + self::COL_GAP;
        }
        $lane0End = $x;
        $width    = $x;
        foreach (array_slice($lanes, 1, null, true) as $i => $columns) {
            $start = $xs[0][0] + self::BOX_DX + self::COL_GAP;
            $step  = ($lane0End - $start) / max(1, count($columns));
            $x     = $start;
            foreach ($columns as $j => $col) {
                $xs[$i][$j] = max($x, (int) ($start + $j * $step));
                $x          = $xs[$i][$j] + $col['width'] + self::COL_GAP;
            }
            $width = max($width, $x);
        }

        // Draw columns and the lane lines between them
        foreach ($lanes as $i => $columns) {
            $prevStem = null;
            foreach ($columns as $j => $col) {
                $stemX = $xs[$i][$j];
                $this->drawColumn($col, $stemX, $laneY[$i]);

                if ($prevStem !== null) {
                    $this->line($prevStem, $laneY[$i], $stemX, $laneY[$i], $this->columnFlyable($col));
                } elseif ($i > 0) {
                    // A later lane joins the origin's stem (or lane 0's first hull)
                    $joinX = $xs[0][0];
                    $gold  = $this->columnFlyable($col);
                    $this->line($joinX, $laneY[0], $joinX, $laneY[$i], $gold);
                    $this->line($joinX, $laneY[$i], $stemX, $laneY[$i], $gold);
                }
                $prevStem = $stemX;
            }
        }

        return [
            'width'  => $width + self::MARGIN,
            'height' => $y - self::LANE_GAP + self::MARGIN,
            'boxes'  => $this->boxes,
            'lines'  => $this->sortedLines(),
            'dots'   => $this->dots,
        ];
    }

    /** Grey first so overlapping gold segments stay visible. */
    private function sortedLines(): array
    {
        $lines = $this->lines;
        usort($lines, fn($a, $b) => $a['gold'] <=> $b['gold']);
        return $lines;
    }

    /** Width and extent above/below the lane line, for a column positioned with its stem at x = 0. */
    private function measure(array $col): array
    {
        if (isset($col['fan'])) {
            $above = 0;
            $width = 0;
            $top   = $this->firstRowTop();
            foreach ($col['fan'] as $k => $hull) {
                [$rowW, $rowH] = $this->rowSize($hull);
                $top   = $k === 0 ? $top : $top - self::V_GAP - $rowH;
                $above = max($above, -$top);
                $width = max($width, self::BOX_DX + self::DIAG + $rowW);
            }
            [, $firstH] = $this->rowSize($col['fan'][0]);
            return ['width' => $width, 'above' => $above, 'below' => max(0, $this->firstRowTop() + $firstH)];
        }

        [, $baseH] = $this->boxSize($col['base']);
        $width = self::BOX_DX + $this->footprint($col['base']);
        $above = $this->anchor();
        foreach (array_filter([$col['direct']]) as $node) {
            $h      = $this->boxSize($node)[1];
            $above += self::V_GAP + $h;
            $width  = max($width, self::BOX_DX + $this->footprint($node));
        }
        foreach (array_merge($col['diag'], $col['extra'] ?? []) as $node) {
            $h      = $this->boxSize($node)[1];
            $above += self::V_GAP + $h;
            $width  = max($width, self::BOX_DX + self::DIAG + $this->footprint($node));
        }
        return ['width' => $width, 'above' => $above, 'below' => $baseH - $this->anchor()];
    }

    private function drawColumn(array $col, int $stemX, int $laneY): void
    {
        $this->dots[] = ['x' => $stemX, 'y' => $laneY, 'gold' => $this->columnFlyable($col)];

        if (isset($col['fan'])) {
            $top = $laneY + $this->firstRowTop();
            foreach ($col['fan'] as $k => $hull) {
                [, $rowH] = $this->rowSize($hull);
                if ($k > 0) {
                    $top -= self::V_GAP + $rowH;
                }
                $this->drawRow($hull, $stemX, $stemX + self::BOX_DX + self::DIAG, $top);
            }
            return;
        }

        $boxX = $stemX + self::BOX_DX;
        $top  = $laneY - $this->anchor();
        $this->box($col['base'], $boxX, $top, 'root');

        $stemTop = $laneY;
        $goldTop = $laneY;
        if ($col['direct'] !== null) {
            [, $h] = $this->boxSize($col['direct']);
            $top  -= self::V_GAP + $h;
            $this->box($col['direct'], $boxX, $top, 'branch');
            $attach = $top + self::ATTACH;
            $gold   = $this->flyable($col['direct']);
            $this->line($stemX, $attach, $boxX, $attach, $gold);
            $stemTop = $attach;
            $goldTop = $gold ? $attach : $goldTop;
        }
        foreach (array_merge($col['diag'], $col['extra'] ?? []) as $node) {
            [, $h] = $this->boxSize($node);
            $top -= self::V_GAP + $h;
            $x    = $boxX + self::DIAG;
            $this->box($node, $x, $top, 'branch');
            $attach = $top + self::ATTACH;
            $from   = $attach + ($x - $stemX);            // 45° up to the box
            $gold   = $this->flyable($node);
            $this->line($stemX, $from, $x, $attach, $gold);
            $stemTop = min($stemTop, $from);
            $goldTop = $gold ? min($goldTop, $from) : $goldTop;
        }

        // Stem: gold up to the highest group the pilot can fly, grey beyond
        if ($goldTop < $laneY) {
            $this->line($stemX, $laneY, $stemX, $goldTop, true);
        }
        if ($stemTop < $goldTop) {
            $this->line($stemX, $goldTop, $stemX, $stemTop, false);
        }
    }

    /** A capital hull and its own branches, side by side, on a diagonal off the fan stem. */
    private function drawRow(array $hull, int $stemX, int $x, int $top): void
    {
        $attach = $top + self::ATTACH;
        $from   = $attach + ($x - $stemX);                 // 45° up to the box
        $gold   = $this->flyable($hull);
        $this->line($stemX, $from, $x, $attach, $gold);
        $this->fanStem($stemX, $from, $gold);

        $prevRight = null;
        foreach (array_merge([$hull], $hull['branches']) as $k => $node) {
            [$w] = $this->boxSize($node);
            $this->box($node, $x, $top, $k === 0 ? 'root' : 'branch');
            if ($prevRight !== null) {
                // Through the tiles, not the labels, which can run past a narrow box
                $y = $top + $this->anchor();
                $this->line($prevRight, $y, $x, $y, $this->flyable($node));
            }
            $prevRight = $x + $w;
            $x        += $this->footprint($node) + self::ROW_GAP;
        }
    }

    /** Fan stems run from the lane line up to each capital's branch point. */
    private function fanStem(int $stemX, int $toY, bool $gold): void
    {
        $laneY = $this->dots[array_key_last($this->dots)]['y'];
        if ($toY < $laneY) {
            $this->line($stemX, $laneY, $stemX, $toY, $gold);
        }
    }

    // ── Sizes and helpers ─────────────────────────────────────────────────

    /** [width, height] of a group box. */
    private function boxSize(array $node): array
    {
        $n    = count($node['ships']);
        $cols = min(self::COLS, $n);
        $rows = (int) ceil($n / self::COLS);
        return [
            2 * self::PAD + $cols * self::TILE_W + ($cols - 1) * self::GAP,
            self::LABEL + 2 * self::PAD + $rows * self::TILE_H + ($rows - 1) * self::GAP,
        ];
    }

    /** Horizontal space a box needs: its label may run past a narrow box. */
    private function footprint(array $node): int
    {
        return max($this->boxSize($node)[0], self::PAD + mb_strlen($node['name']) * self::LABEL_CH);
    }

    /** [width, height] of a capital row: the hull and its branches side by side. */
    private function rowSize(array $hull): array
    {
        $w = -self::ROW_GAP;
        $h = 0;
        foreach (array_merge([$hull], $hull['branches']) as $node) {
            $w += $this->footprint($node) + self::ROW_GAP;
            $h  = max($h, $this->boxSize($node)[1]);
        }
        return [$w, $h];
    }

    /** Lane lines run through the middle of a hull box's first row of tiles. */
    private function anchor(): int
    {
        return self::LABEL + self::PAD + intdiv(self::TILE_H, 2);
    }

    /** Top of the lowest capital row, relative to the lane line: its diagonal starts on the line. */
    private function firstRowTop(): int
    {
        return -(self::ATTACH + self::BOX_DX + self::DIAG);
    }

    private function box(array $node, int $x, int $y, string $kind): void
    {
        [$w, $h] = $this->boxSize($node);
        $this->boxes[] = ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'kind' => $kind, 'node' => $node];
    }

    private function line(int $x1, int $y1, int $x2, int $y2, bool $gold): void
    {
        $this->lines[] = ['x1' => $x1, 'y1' => $y1, 'x2' => $x2, 'y2' => $y2, 'gold' => $gold];
    }

    private function flyable(array $node): bool
    {
        foreach ($node['ships'] as $ship) {
            if (isset($this->levels[$ship['type_id']])) return true;
        }
        return false;
    }

    private function columnFlyable(array $col): bool
    {
        if (isset($col['fan'])) {
            return array_filter($col['fan'], fn($h) => $this->flyable($h)) !== [];
        }
        return $this->flyable($col['base']);
    }
}
