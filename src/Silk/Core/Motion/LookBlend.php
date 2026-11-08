<?php

namespace Silk\Core\Motion;

use Silk\Contracts\Look\FontRole;
use Silk\Contracts\Look\FontSpec;
use Silk\Contracts\Look\Look;
use Silk\Contracts\Look\Material;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Motion;
use Silk\Contracts\Look\PartLook;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\State;
use Silk\Contracts\Look\SyntaxToken;
use Surface\NutsAndBolts\Color;

/**
 * A theme switch on its way (spec 7.5): colours go from $from to $to by the spring's value; metrics, fonts
 * and part numbers are $to's at once, because they move the layout.
 */
final class LookBlend implements Look
{
    public function __construct(private readonly Look $from, public readonly Look $to, public readonly Spring $t) {}

    /** This blend held at its value now, as the start of the next switch. */
    public function frozen(): self
    {
        return new self($this->from, $this->to, new Spring($this->t->value(), SpringPreset::Smooth));
    }

    public function color(Role $r): Color
    {
        return self::mix($this->from->color($r), $this->to->color($r), $this->t->value());
    }

    /** Dark as the look it is heading to. */
    public function dark(): bool
    {
        return $this->to->dark();
    }

    /** What the look it is heading to is made of. */
    public function material(): Material
    {
        return $this->to->material();
    }

    public function liquid(): bool
    {
        return $this->to->liquid();
    }

    /** The family of the look it is heading to. */
    public function family(): string
    {
        return $this->to->family();
    }

    /** The twins blend as their looks do. */
    public function darkTwin(): Look
    {
        return new self($this->from->darkTwin(), $this->to->darkTwin(), $this->t);
    }

    /** Two colours blend; to or from the text colour (null) changes half way. */
    public function syntax(SyntaxToken $t): ?Color
    {
        [$a, $b, $v] = [$this->from->syntax($t), $this->to->syntax($t), $this->t->value()];

        return $a === null || $b === null ? ($v >= 0.5 ? $b : $a) : self::mix($a, $b, $v);
    }

    public function metric(Metric $m): float
    {
        return $this->to->metric($m);
    }

    public function font(FontRole $role = FontRole::Ui): FontSpec
    {
        return $this->to->font($role);
    }

    public function motion(): Motion
    {
        return $this->to->motion();
    }

    public function part(string $widget, string $part, State $state, array $classes = []): PartLook
    {
        [$a, $b, $t] = [$this->from->part($widget, $part, $state, $classes), $this->to->part($widget, $part, $state, $classes), $this->t->value()];

        return new PartLook(self::mix($a->background, $b->background, $t), self::mix($a->border, $b->border, $t), self::mix($a->text, $b->text, $t),
            $b->radius, $b->borderWidth, $b->shadow, self::mix($a->shadowColor, $b->shadowColor, $t), $b->extra,
            self::mix($a->borderTop, $b->borderTop, $t), self::mix($a->borderBottom, $b->borderBottom, $t));
    }

    /** a to b by t, per channel; a missing end blends as the other at alpha 0 (Raven). */
    public static function mix(?Color $a, ?Color $b, float $t): ?Color
    {
        if ($a === null || $b === null) {
            $a ??= $b === null ? null : new Color($b->red, $b->green, $b->blue, 0.0);
            $b ??= $a === null ? null : new Color($a->red, $a->green, $a->blue, 0.0);
            if ($a === null) {
                return null;
            }
        }
        if ($t <= 0.0) {
            return $a;
        }

        return new Color(
            $a->red + ($b->red - $a->red) * $t,
            $a->green + ($b->green - $a->green) * $t,
            $a->blue + ($b->blue - $a->blue) * $t,
            $a->alpha + ($b->alpha - $a->alpha) * $t,
        );
    }
}
