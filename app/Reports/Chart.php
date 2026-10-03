<?php

namespace App\Reports;

/**
 * A line chart of a report's period (filled) and the previous one, as SVG.
 */
class Chart
{
    const WIDTH = 800;
    const HEIGHT = 220;
    const LEFT = 40;
    const BOTTOM = 24;
    const TOP = 10;

    public static function svg(array $chart)
    {
        $labels = $chart['labels'];
        [$current, $previous] = [$chart['datasets'][0]['data'], $chart['datasets'][1]['data']];
        $count = count($labels);
        $max = self::niceMax(max(array_merge([1], $current, $previous)));
        $plot_width = self::WIDTH - self::LEFT - 10;
        $plot_height = self::HEIGHT - self::TOP - self::BOTTOM;

        $x = function ($i) use ($count, $plot_width) {
            return round(self::LEFT + ($count > 1 ? $i * $plot_width / ($count - 1) : $plot_width / 2), 1);
        };
        $y = function ($value) use ($max, $plot_height) {
            return round(self::TOP + $plot_height - $value * $plot_height / $max, 1);
        };

        $svg = '<svg class="rpt-chart" viewBox="0 0 '.self::WIDTH.' '.self::HEIGHT.'" role="img" aria-label="'.e($chart['types'][$chart['type']] ?? '').'">';

        // Grid and scale.
        foreach ([0, 0.25, 0.5, 0.75, 1] as $part) {
            $value = $max * $part;
            $svg .= '<line class="rpt-chart__grid" x1="'.self::LEFT.'" x2="'.(self::WIDTH - 10).'" y1="'.$y($value).'" y2="'.$y($value).'"/>'
                .'<text class="rpt-chart__label" x="'.(self::LEFT - 6).'" y="'.($y($value) + 4).'" text-anchor="end">'.e(self::number($value)).'</text>';
        }

        // Labels, thinned out to fit.
        $step = max(1, (int) ceil($count / 12));
        foreach ($labels as $i => $label) {
            if ($i % $step == 0) {
                $svg .= '<text class="rpt-chart__label" x="'.$x($i).'" y="'.(self::HEIGHT - 6).'" text-anchor="middle">'.e($label).'</text>';
            }
        }

        $points = function ($data) use ($x, $y) {
            return implode(' ', array_map(function ($i, $value) use ($x, $y) {
                return $x($i).','.$y($value);
            }, array_keys($data), $data));
        };
        $svg .= '<polyline class="rpt-chart__previous" points="'.$points($previous).'"/>';
        $svg .= '<polygon class="rpt-chart__area" points="'.$x(0).','.$y(0).' '.$points($current).' '.$x($count - 1).','.$y(0).'"/>';
        $svg .= '<polyline class="rpt-chart__current" points="'.$points($current).'"/>';

        // Points with the numbers on hover.
        foreach ($current as $i => $value) {
            $svg .= '<circle class="rpt-chart__point" cx="'.$x($i).'" cy="'.$y($value).'" r="3"><title>'
                .e($labels[$i].': '.$value.' ('.$chart['datasets'][1]['label'].': '.($previous[$i] ?? 0).')').'</title></circle>';
        }

        return $svg.'</svg>';
    }

    /**
     * The top of the scale: four whole, round steps.
     */
    public static function niceMax($value)
    {
        $raw = max(1, $value / 4);
        $power = 10 ** floor(log10($raw));
        foreach ([1, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10] as $factor) {
            if ($factor * $power >= $raw && $factor * $power == (int) ($factor * $power)) {
                return (int) ($factor * $power * 4);
            }
        }

        return (int) (10 * $power * 4);
    }

    protected static function number($value)
    {
        return $value == (int) $value ? (string) (int) $value : (string) round($value, 1);
    }
}
