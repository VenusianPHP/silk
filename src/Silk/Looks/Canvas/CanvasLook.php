<?php

namespace Silk\Looks\Canvas;

use Closure;
use Silk\Contracts\Look\Look;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\State;
use Surface\NutsAndBolts\Color;

/**
 * What a canvas widget (Timeline, NodeGraph) is drawn from (Raven rvn_canvas_look, canvas_look.c:59-81): the
 * colours of the theme it takes, whether it is dark, and the preset in use. Auto is the look of the theme's
 * family. A part is resolved as Raven's rvn_canvas_part: the widget's base (derivations of the roles), then the
 * theme's style rows for the preset's classes, then what was given to the widget, then to the item.
 */
final class CanvasLook
{
    public readonly Color $text;

    public readonly Color $accent;

    public readonly Color $window;

    public readonly Color $input;

    public readonly Color $panel;

    public readonly Color $popup;

    public readonly Color $popupBorder;

    public readonly Color $border;

    public readonly Color $separator;

    public readonly Color $success;

    public readonly Color $danger;

    public readonly Color $warning;

    /** The text is light on a dark theme; Blueprint, Blender and Neon are always dark. */
    public readonly bool $dark;

    /** 'raven', 'macos', 'gnome', 'windows' for Auto, else the preset's name. */
    public readonly string $preset;

    public function __construct(public readonly Look $look, CanvasPreset $preset)
    {
        foreach (['text' => Role::Text, 'accent' => Role::Accent, 'window' => Role::Window, 'input' => Role::Input, 'panel' => Role::Panel,
            'popup' => Role::Popup, 'popupBorder' => Role::PopupBorder, 'border' => Role::Border, 'separator' => Role::Separator,
            'success' => Role::Success, 'danger' => Role::Danger, 'warning' => Role::Warning] as $field => $role) {
            $this->{$field} = $look->color($role);
        }
        $this->preset = $preset === CanvasPreset::Auto ? $look->family() : $preset->value;
        $this->dark = $preset->night() || self::lightness($this->text) > 0.5;
    }

    /** 0..1: how light a colour is, to put dark or light text on it (rvn_color_lightness, canvas_look.c:52-57). */
    public static function lightness(Color $c): float
    {
        return 0.2126 * $c->red + 0.7152 * $c->green + 0.0722 * $c->blue;
    }

    public function is(string $preset): bool
    {
        return $this->preset === $preset;
    }

    /** The style classes of the preset: its name, and its name with -dark or -light (Raven's dark branches). */
    public function classes(): array
    {
        return [$this->preset, $this->preset.($this->dark ? '-dark' : '-light')];
    }

    /**
     * A part, as rvn_canvas_part (canvas_look.c:181-185): $base, then the theme's rows for the widget's part and
     * the preset's classes, then the widget's overrides.
     *
     * @param  Closure(PartStyle, string, State, CanvasLook): void  $base
     */
    public function part(string $widget, string $part, State $state, Closure $base, ?PartOverrides $over = null): PartStyle
    {
        $style = new PartStyle;
        $base($style, $part, $state, $this);
        [$row, $mask] = PartStyle::fromPartLook($this->look->part($widget, $part, $state, $this->classes()));
        $style->apply($row, $mask);
        $over?->applyTo($part, $style);

        return $style;
    }
}
