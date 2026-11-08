<?php

namespace Silk\Widgets\Concerns;

use Silk\Contracts\Look\PartLook;
use Silk\Contracts\Look\State;
use Silk\Core\Motion\LookBlend;

/** A part's look eased between its states, and two looks mixed: Raven rvn_part_look_eased and rvn_part_look_mix (look.c:308-348). */
trait EasesParts
{
    /** Rest, then Hover by $hover, then Pressed by $press; a disabled part rests in Disabled. */
    protected function easedPart(string $widget, string $part, array $classes, float $hover, float $press): PartLook
    {
        $look = $this->look();
        if (! $this->isEnabled()) {
            return $look->part($widget, $part, State::Disabled, $classes);
        }
        $p = $look->part($widget, $part, State::Normal, $classes);
        $hover > 0.001 && $p = self::mixParts($p, $look->part($widget, $part, State::Hover, $classes), min(1.0, $hover));
        $press > 0.001 && $p = self::mixParts($p, $look->part($widget, $part, State::Pressed, $classes), min(1.0, $press));

        return $p;
    }

    /** a to b by t: colours (a missing end takes the other's colour), widths, radius and the numbers of extra. */
    protected static function mixParts(PartLook $a, PartLook $b, float $t): PartLook
    {
        $t = max(0.0, min(1.0, $t));
        if ($t <= 0.0) {
            return $a;
        }
        if ($t >= 1.0) {
            return $b;
        }
        $n = fn (float $x, float $y) => $x + ($y - $x) * $t;
        $extra = $b->extra;
        foreach ($b->extra as $k => $v) {
            if (is_float($v)) {
                $extra[$k] = $n(is_float($a->extra[$k] ?? null) ? $a->extra[$k] : ($k === 'opacity' ? 1.0 : 0.0), $v);
            }
        }
        foreach ($a->extra as $k => $v) {
            if (is_float($v) && ! isset($b->extra[$k])) {
                $extra[$k] = $n($v, $k === 'opacity' ? 1.0 : 0.0);
            }
        }

        return new PartLook(LookBlend::mix($a->background, $b->background, $t), LookBlend::mix($a->border, $b->border, $t),
            LookBlend::mix($a->text, $b->text, $t), $n($a->radius, $b->radius), $n($a->borderWidth, $b->borderWidth),
            $n($a->shadow, $b->shadow), LookBlend::mix($a->shadowColor, $b->shadowColor, $t), $extra,
            LookBlend::mix($a->borderTop, $b->borderTop, $t), LookBlend::mix($a->borderBottom, $b->borderBottom, $t));
    }
}
