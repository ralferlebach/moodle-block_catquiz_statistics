<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace block_catquiz_statistics\research;

/**
 * SVG diagram of an observed-variable path model (Issue #8).
 *
 * Layered layout by longest path from the exogenous variables; arrows carry the
 * standardised path coefficient. The diagram visualises statistical direction
 * only — the surrounding page states that path coefficients are associations.
 *
 * @package    block_catquiz_statistics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class path_diagram {
    /** @var int Node box width. */
    private const W = 176;

    /** @var int Node box height. */
    private const H = 40;

    /** @var int Horizontal gap between layers. */
    private const GX = 90;

    /** @var int Vertical gap between nodes. */
    private const GY = 34;

    /**
     * Render the SVG.
     *
     * @param array $equations endogenous => predictors
     * @param array $fit Result of path_model::fit() (equations with paths).
     * @param array $labels column => display label
     * @return string SVG markup (all text escaped)
     */
    public static function svg(array $equations, array $fit, array $labels = []): string {
        $nodes = array_values(array_unique(array_merge(array_keys($equations), ...array_values($equations))));
        $depth = [];
        $depthof = function (string $v) use (&$depthof, &$depth, $equations): int {
            if (isset($depth[$v])) {
                return $depth[$v];
            }
            $d = 0;
            foreach ($equations[$v] ?? [] as $p) {
                $d = max($d, $depthof($p) + 1);
            }
            return $depth[$v] = $d;
        };
        foreach ($nodes as $v) {
            $depthof($v);
        }
        $layers = [];
        foreach ($nodes as $v) {
            $layers[$depth[$v]][] = $v;
        }
        ksort($layers);
        $maxrows = max(array_map('count', $layers));
        $height = $maxrows * (self::H + self::GY) + self::GY;
        $width = count($layers) * (self::W + self::GX) + self::GX - 40;
        $pos = [];
        foreach ($layers as $l => $vs) {
            $offset = ($height - count($vs) * (self::H + self::GY) + self::GY) / 2;
            foreach (array_values($vs) as $i => $v) {
                $pos[$v] = [20 + $l * (self::W + self::GX), $offset + $i * (self::H + self::GY)];
            }
        }

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" class="la-pathdiagram" viewBox="0 0 ' . $width . ' ' . $height
            . '" role="img" aria-label="' . s(get_string('analysis:pathdiagram', 'block_catquiz_statistics')) . '">'
            . '<defs><marker id="la-arrow" viewBox="0 0 10 10" refX="10" refY="5" markerWidth="7" markerHeight="7" orient="auto">'
            . '<path d="M0,0 L10,5 L0,10 z" class="la-arrowhead"/></marker></defs>';
        $top = 0;
        $bottom = $height;
        $edges = '';
        foreach ($equations as $to => $preds) {
            foreach ($preds as $from) {
                $path = $fit['equations'][$to]['paths'][$from] ?? null;
                $x1 = $pos[$from][0] + self::W;
                $y1 = $pos[$from][1] + self::H / 2;
                $x2 = $pos[$to][0];
                $y2 = $pos[$to][1] + self::H / 2;
                $class = 'la-edge' . (($path && $path['p'] < 0.05) ? ' la-edge-sig' : '');
                $span = $depth[$to] - $depth[$from];
                if ($span > 1) {
                    // Skip edges arc around the intermediate layers instead of running behind their nodes:
                    // above when they come from above or the same level, below when they come from below.
                    $offset = 45 * ($span - 1) + self::H;
                    if ($y1 > $y2) {
                        $cy = max($y1, $y2) + $offset;
                        $bottom = max($bottom, $cy);
                    } else {
                        $cy = min($y1, $y2) - $offset;
                        $top = min($top, $cy);
                    }
                    $mx = ($x1 + $x2) / 2;
                    $edges .= '<path d="M' . $x1 . ',' . $y1 . ' Q' . $mx . ',' . $cy . ' ' . $x2 . ',' . $y2
                        . '" fill="none" class="' . $class . '" marker-end="url(#la-arrow)"/>';
                    $lx = $mx;
                    $ly = ($y1 + 2 * $cy + $y2) / 4 - 4;
                } else {
                    $edges .= '<line x1="' . $x1 . '" y1="' . $y1 . '" x2="' . $x2 . '" y2="' . $y2 . '" class="' . $class
                        . '" marker-end="url(#la-arrow)"/>';
                    $lx = ($x1 + $x2) / 2;
                    $ly = ($y1 + $y2) / 2 - 6;
                }
                if ($path) {
                    $beta = $path['beta'] ?? null;
                    $text = $beta === null ? format_float($path['b'], 2) : format_float($beta, 2);
                    $edges .= '<text x="' . $lx . '" y="' . $ly . '" class="la-edgelabel" text-anchor="middle">'
                        . s($text) . '</text>';
                }
            }
        }
        $svg .= $edges;
        foreach ($pos as $v => [$x, $y]) {
            $label = $labels[$v] ?? $v;
            $short = \core_text::strlen($label) > 26 ? \core_text::substr($label, 0, 25) . '…' : $label;
            $r2 = isset($fit['equations'][$v]) ? ' (R² ' . format_float($fit['equations'][$v]['r2'], 2) . ')' : '';
            $svg .= '<g class="la-node' . (isset($equations[$v]) ? ' la-node-endo' : '') . '"><title>' . s($label . $r2)
                . '</title><rect x="' . $x . '" y="' . $y . '" width="' . self::W . '" height="' . self::H . '" rx="3"/>'
                . '<text x="' . ($x + self::W / 2) . '" y="' . ($y + self::H / 2 + 4) . '" text-anchor="middle">' . s($short)
                . '</text></g>';
        }
        $svg .= '</svg>';
        if ($top < 0) {
            $pad = (int) ceil(-$top) + 10;
            $svg = str_replace(
                'viewBox="0 0 ' . $width . ' ' . $height . '"',
                'viewBox="0 ' . (-$pad) . ' ' . $width . ' ' . ($height + $pad) . '"',
                $svg
            );
        }
        return $svg;
    }
}
