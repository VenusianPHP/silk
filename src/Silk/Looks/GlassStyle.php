<?php

namespace Silk\Looks;

use Silk\Contracts\Look\Look;
use Silk\Contracts\Look\Material;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\SurfaceKind;
use Silk\Contracts\Painter\GlassLook;
use Surface\NutsAndBolts\Color;

/**
 * Raven's glass_style (widgets/common.c:48-175): the glass a kind of surface is in a look, at a hover and a press (0..1),
 * then tuned by the look's Glass* metrics. Colours go through bytes as Raven's do (rvn_color_fade, rvn_color_mix).
 */
final class GlassStyle
{
    public static function of(Look $look, SurfaceKind $kind, float $hover = 0.0, float $press = 0.0, ?Material $material = null): GlassLook
    {
        $dark = $look->dark();
        $rim = $dark ? 0.55 : 0.85;
        $hover = max(0.0, min(1.0, $hover));
        $press = max(0.0, min(1.0, $press));
        [$frost, $refraction, $saturation, $shadow, $bevel] = [1.0, 10.0, 1.5, 0.0, 0.0];

        switch ($kind) {
            case SurfaceKind::Accent:
                $tint = self::fade(self::mix(self::mix($look->color(Role::Accent), $look->color(Role::AccentHovered), $hover), $look->color(Role::AccentPressed), $press), 0.84);
                [$saturation, $shadow] = [1.3, 7.0];
                break;
            case SurfaceKind::Input:
                $tint = $look->color(Role::Input);
                $rim *= 0.55;
                $refraction = 6.0;
                break;
            case SurfaceKind::Bar:
                $tint = $look->color(Role::Panel);
                $rim *= 0.6;
                [$refraction, $bevel] = [8.0, 6.0];
                break;
            case SurfaceKind::Panel:
                $tint = $look->color(Role::Panel);
                [$refraction, $bevel] = [14.0, 12.0];
                break;
            case SurfaceKind::Popup:
                $tint = $look->color(Role::Popup);
                [$refraction, $bevel, $saturation, $shadow] = [14.0, 10.0, 1.7, 22.0];
                break;
            case SurfaceKind::Lens:
                $tint = new Color(1, 1, 1, round(($dark ? 0.06 : 0.16) * 255) / 255);
                $rim = 1.0;
                [$frost, $refraction, $saturation, $shadow] = [0.0, 16.0, 1.15, 9.0];
                break;
            case SurfaceKind::Control:
            default:
                $tint = self::mix(self::mix($look->color(Role::Control), $look->color(Role::ControlHovered), $hover), $look->color(Role::ControlPressed), $press);
                [$frost, $shadow] = [0.9, 6.0];
        }

        // the material: how much of what is behind comes through, and in which colour
        $alpha = $tint->alpha;
        switch ($material ?? $look->material()) {
            case Material::Glass:
                // clear: barely tinted, barely blurred, and a rim that bends like a lens
                $tint = self::fade($tint, $kind === SurfaceKind::Accent ? 0.82 : 0.55);
                $frost *= 0.22;
                $refraction *= 1.5;
                $saturation = 1.0 + ($saturation - 1.0) * 0.5;
                $rim = min($rim * 1.2, 1.0);
                break;
            case Material::TintedGlass:
                if ($kind !== SurfaceKind::Accent && $kind !== SurfaceKind::Lens) {
                    // frosted, and coloured: the accent shows through every surface, stronger on what floats higher
                    $strength = $kind === SurfaceKind::Input ? 0.25 : ($kind === SurfaceKind::Bar ? 0.4 : 0.55);
                    $share = max(0.0, min(0.78, $alpha * 1.35 + 0.14));
                    $accent = $look->color(Role::Accent);
                    $accent = self::fade($accent, $share / max($accent->alpha, 0.01));
                    $tint = self::mix(self::fade($tint, $share / max($alpha, 0.01)), $accent, $strength);
                }
                $saturation = 1.25;
                break;
            default:
                break;
        }
        // a palette made for solid surfaces would hide everything: glass keeps some of what is behind
        $alpha = $tint->alpha;
        if ($kind !== SurfaceKind::Accent && $alpha > 0.74) {
            $tint = self::fade($tint, 0.74 / $alpha);
        }

        return self::tune($look, new GlassLook(
            $tint,
            new Color(1, 1, 1, max(0.0, min(1.0, $rim + 0.25 * $press))),
            $frost,
            $refraction,
            $saturation,
            // touched glass lights up
            ($dark ? 0.05 : 0.03) * $press,
            $bevel,
            $shadow,
            $look->color(Role::Shadow),
        ));
    }

    /** The glass of the look (or of the widget): each Glass* metric multiplies what the material gives (glass_tune, common.c:128-143). */
    public static function tune(Look $look, GlassLook $glass): GlassLook
    {
        $f = $look->metric(Metric::GlassFrost);
        $r = $look->metric(Metric::GlassRefraction);
        $s = $look->metric(Metric::GlassSaturation);
        $m = $look->metric(Metric::GlassRim);
        $d = $look->metric(Metric::GlassShadow);
        $rim = $glass->rim;

        return new GlassLook(
            $glass->tint,
            $rim === null || $m == 1.0 ? $rim : new Color($rim->red, $rim->green, $rim->blue, floor(max(0.0, min(255.0, round($rim->alpha * 255) * max($m, 0.0)))) / 255),
            $f != 1.0 ? max(0.0, min(1.0, $glass->frost * $f)) : $glass->frost,
            $r != 1.0 ? $glass->refraction * max($r, 0.0) : $glass->refraction,
            $s != 1.0 ? max(0.0, 1.0 + ($glass->saturation - 1.0) * $s) : $glass->saturation,
            $glass->glow,
            $glass->bevel,
            $d != 1.0 ? $glass->shadow * max($d, 0.0) : $glass->shadow,
            $glass->shadowColor,
        );
    }

    /** rvn_color_fade: the alpha scaled, through bytes. */
    private static function fade(Color $c, float $by): Color
    {
        return new Color($c->red, $c->green, $c->blue, floor(round($c->alpha * 255) * max(0.0, min(1.0, $by)) + 0.5) / 255);
    }

    /** rvn_color_mix: every channel, alpha too, through bytes. */
    private static function mix(Color $a, Color $b, float $t): Color
    {
        $t = max(0.0, min(1.0, $t));
        $one = fn (float $x, float $y): float => floor(round($x * 255) + (round($y * 255) - round($x * 255)) * $t + 0.5) / 255;

        return new Color($one($a->red, $b->red), $one($a->green, $b->green), $one($a->blue, $b->blue), $one($a->alpha, $b->alpha));
    }
}
