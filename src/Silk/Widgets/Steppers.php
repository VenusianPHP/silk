<?php

namespace Silk\Widgets;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Look\Look;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\State;
use Silk\Contracts\Painter\Painter;
use Silk\Painter\Icons;
use Silk\Painter\PartBox;
use Surface\NutsAndBolts\Color;

/**
 * The steppers of a number field, by the named variant its style picks (Raven spin_box.c): 'drag' has none
 * (the Raven looks scrub), 'stacked' is one small button cut in two (macOS), 'sideBySide' is minus then plus at
 * the end (GNOME). Static, so any widget with steppers (SpinBox, TimePicker) draws and hits them the same way.
 */
final class Steppers
{
    /** @return array{field: Rect, up: ?Rect, down: ?Rect} where the field is drawn, and each button */
    public static function rects(string $variant, Rect $b, float $controlHeight): array
    {
        $right = $b->x + $b->w;

        return match ($variant) {
            // spin_box.c:77, 83-85, 97-98
            'stacked' => ['field' => new Rect($b->x, $b->y, max(0.0, $b->w - 19), $b->h),
                'up' => new Rect($right - 15, $b->y, 15.0, floor($b->h / 2)),
                'down' => new Rect($right - 15, $b->y + floor($b->h / 2), 15.0, floor($b->h / 2))],
            // spin_box.c:76, 99-101
            'sideBySide' => (function () use ($b, $right, $controlHeight) {
                $w = min($controlHeight, floor($b->w * 0.24));

                return ['field' => $b, 'up' => new Rect($right - $w, $b->y, $w, $b->h), 'down' => new Rect($right - 2 * $w, $b->y, $w, $b->h)];
            })(),
            default => ['field' => $b, 'up' => null, 'down' => null],
        };
    }

    /** +1 for the button that adds, -1 for the other, 0 for none; a unit of slack each side (spin_box.c:109-116). */
    public static function hit(string $variant, Rect $b, float $controlHeight, float $x, float $y): int
    {
        $r = self::rects($variant, $b, $controlHeight);
        foreach (['up' => 1, 'down' => -1] as $key => $direction) {
            $button = $r[$key];
            if ($button !== null && $x >= $button->x - 1 && $x < $button->x + $button->w + 1 && $y >= $button->y && $y < $button->y + $button->h) {
                return $direction;
            }
        }

        return 0;
    }

    /** Where the typed text goes (spin_box.c:87-93). */
    public static function textArea(string $variant, Rect $b, float $controlHeight, float $paddingX): Rect
    {
        if ($variant === 'stacked') {
            return new Rect($b->x + 5, $b->y, max(0.0, self::rects($variant, $b, $controlHeight)['field']->w - 10), $b->h);
        }
        $buttons = $variant === 'sideBySide' ? 2 * self::rects($variant, $b, $controlHeight)['up']->w : 0.0;
        $px = $buttons > 0 ? $paddingX - 1 : $paddingX * 0.7;

        return new Rect($b->x + $px, $b->y, max(0.0, $b->w - 2 * $px - $buttons), $b->h);
    }

    /**
     * The buttons and their glyphs, after the field (spin_box.c:174-207). $hot: the button under the pointer
     * (+1, -1, 0). $text: the field's text colour, the glyphs' when disabled. Parts: 'Arrow' (each button's box),
     * 'Glyph' (its symbol's colour, Hover when hot); the stacked stepper's box is the button look.
     */
    public static function paint(Painter $p, Look $look, string $variant, Rect $b, float $controlHeight, int $hot, bool $enabled, Color $text): void
    {
        if ($variant === 'drag') {
            return;
        }
        if ($variant === 'stacked') {
            $stepper = new Rect($b->x + $b->w - 15, $b->y + floor(($b->h - 22) / 2), 15.0, min(22.0, $b->h));
            $box = $look->part('Button', 'Body', $enabled ? State::Normal : State::Disabled);
            PartBox::paint($p, $stepper, $box->background, $box->radius, $box->border, $box->borderWidth, $box->borderTop, $box->borderBottom);
            $p->rect(new Rect($stepper->x + 2, $stepper->y + floor($stepper->h / 2), $stepper->w - 4, 1.0), $look->color(Role::Separator));
        }
        $rects = self::rects($variant, $b, $controlHeight);
        foreach (['up' => 1, 'down' => -1] as $key => $direction) {
            $r = $rects[$key];
            [$cx, $cy] = [$r->x + $r->w / 2, $r->y + $r->h / 2];
            $state = ! $enabled ? State::Disabled : ($hot === $direction ? State::Hover : State::Normal);
            $glyph = $enabled ? ($look->part('Steppers', 'Glyph', $state)->text ?? $text) : $text;
            if ($variant === 'sideBySide') {
                $arrow = $look->part('Steppers', 'Arrow', $state);
                if ($arrow->background !== null) {
                    PartBox::paint($p, $r, $arrow->background, $arrow->radius, $arrow->border, $arrow->borderWidth);
                }
                $glyph = $arrow->text ?? $glyph;
                // list-remove and list-add, the symbolic icons
                $p->line($cx - 6, $cy, $cx + 6, $cy, $glyph, 1.5);
                if ($direction > 0) {
                    $p->line($cx, $cy - 6, $cx, $cy + 6, $glyph, 1.5);
                }
            } else {
                Icons::chevron($p, $cx, $cy + ($direction > 0 ? 1.0 : -1.0), 9.0 * 0.6, $direction > 0 ? -M_PI_2 : M_PI_2, $glyph);
            }
        }
    }
}
