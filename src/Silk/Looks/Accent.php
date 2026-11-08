<?php

namespace Silk\Looks;

use Silk\Contracts\Look\Role;
use Silk\Painter\RavenPaint;
use Surface\NutsAndBolts\Color;

/** The roles a look takes from one accent colour: Raven's rvn_theme_set_accent (theme.c:230-299), per family. */
final class Accent
{
    /**
     * @param string $family 'macos', 'gnome' or any other (the Raven branch)
     * @return array<string, Color> role name => colour; Switch only when it followed the accent
     */
    public static function derive(Color $accent, string $family, bool $dark, Palette $palette): array
    {
        $o = new Color($accent->red, $accent->green, $accent->blue, 1.0);
        $white = Color::rgb(255, 255, 255);
        $black = Color::rgb(0, 0, 0);
        $mix = RavenPaint::mix(...);
        $bright = 0.2126 * $o->red + 0.7152 * $o->green + 0.0722 * $o->blue;
        $roles = ['Accent' => $o];

        if ($family === 'macos') {
            $roles['AccentHovered'] = $o;
            $roles['AccentPressed'] = $dark ? $mix($o, $white, 0.2) : $mix($o, $black, 0.2);
            $roles['AccentText'] = $bright > 0.62 ? Color::rgba(0, 0, 0, 217 / 255) : $white;
            $roles['Focus'] = self::fade($o, 0.5);
            $roles['Selection'] = $dark ? $mix($o, Color::hex('1e1e1e'), 0.55) : $mix($o, $white, 0.7);
            $roles['Link'] = $dark ? $mix($o, $white, 0.2) : $mix($o, $black, 0.12);
        } elseif ($family === 'gnome') {
            $roles['AccentHovered'] = $mix($o, $white, 0.1);
            $roles['AccentPressed'] = $mix($o, $black, 0.2);
            $roles['AccentText'] = $bright > 0.62 ? Color::rgba(0, 0, 6, 204 / 255) : $white;
            $roles['Link'] = $dark ? $mix($o, $white, 0.35) : $mix($o, $black, 0.15);
            $roles['Focus'] = self::fade($roles['Link'], 0.5);
            $roles['Selection'] = self::fade($o, 0.25);
        } else {
            $roles['AccentHovered'] = $mix($o, $white, 0.18);
            $roles['AccentPressed'] = $mix($o, $black, 0.18);
            $roles['AccentText'] = $bright > 0.62 ? Color::rgb(0x10, 0x10, 0x14) : $white;
            $focus = self::alpha($palette->get(Role::Focus));
            $roles['Focus'] = self::fade($o, ($focus ?: 255) / 255);
            $selection = self::alpha($palette->get(Role::Selection));
            $roles['Selection'] = $selection < 255
                ? self::fade($o, $selection / 255)
                : $mix($palette->get(Role::Window), $o, $dark ? 0.45 : 0.3);
            $roles['Link'] = $dark ? $mix($o, $white, 0.3) : $mix($o, $black, 0.15);
        }
        if ($palette->get(Role::Switch)->toHex() === $palette->get(Role::Accent)->toHex()) {
            $roles['Switch'] = $o;
        }

        return $roles;
    }

    /** Raven rvn_color_fade (base.h:116): the alpha byte scaled, rounded. */
    private static function fade(Color $c, float $opacity): Color
    {
        return new Color($c->red, $c->green, $c->blue, (int) (self::alpha($c) * max(0.0, min(1.0, $opacity)) + 0.5) / 255);
    }

    private static function alpha(Color $c): int
    {
        return (int) round($c->alpha * 255);
    }
}
