<?php

namespace Silk\Looks;

use Silk\Contracts\Look\State;
use Silk\Looks\Styles\DataStyles;
use Silk\Looks\Styles\EditingStyles;
use Silk\Looks\Styles\NodeStyles;
use Silk\Looks\Styles\OverlayStyles;

/** Raven's start look, with Raven's numbers (graph: Theme RavenDark). */
class RavenDark
{
    /** @return array<string, mixed> a theme array */
    public static function theme(string $family = 'sans-serif', string $mono = 'monospace'): array
    {
        return BuiltInThemes::theme('RavenDark', $family, $mono);
    }

    /** The shared Raven styles, as [widget, class, part, state, props]; roles and metrics resolve by name. */
    public static function styles(): array
    {
        return [
            // [widget, class, part, state, props]; roles and metrics are resolved by name
            [null, null, 'Body', null, ['background' => 'Control', 'border' => 'ControlBorder', 'text' => 'Text', 'radius' => 'CornerRadius', 'borderWidth' => 'BorderWidth']],
            [null, null, 'Body', State::Hover, ['background' => 'ControlHovered']],
            [null, null, 'Body', State::Pressed, ['background' => 'ControlPressed']],
            [null, null, 'Body', State::Disabled, ['text' => 'TextDisabled']],
            // Silk's depth, not Raven's: Raven's flat button has no part shadow (look->shadow 0)
            ['Button', null, 'Body', null, ['shadow' => 3.0, 'shadowColor' => 'Shadow', 'shadowAlpha' => 1 / 3]],
            // the default button: the accent, its text AccentText; disabled, the accent at .4 (button.c:56-59)
            ['Button', 'primary', 'Body', null, ['background' => 'Accent', 'text' => 'AccentText', 'border' => null, 'borderWidth' => 0.0]],
            ['Button', 'primary', 'Body', State::Hover, ['background' => 'AccentHovered']],
            ['Button', 'primary', 'Body', State::Pressed, ['background' => 'AccentPressed']],
            ['Button', 'primary', 'Body', State::Disabled, ['background' => 'Accent', 'backgroundAlpha' => 0.4, 'text' => 'TextDisabled']],
            // the flat button: nothing at rest (ControlHovered, transparent, so it fades in), no edge (button.c:60-62)
            ['Button', 'flat', 'Body', null, ['background' => 'ControlHovered', 'backgroundAlpha' => 0.0, 'border' => null, 'borderWidth' => 0.0, 'shadow' => 0.0]],
            ['Button', 'flat', 'Body', State::Hover, ['background' => 'ControlHovered', 'backgroundAlpha' => 1.0]],
            ['Button', 'flat', 'Body', State::Pressed, ['background' => 'ControlPressed', 'backgroundAlpha' => 1.0]],
            ...EditingStyles::list(),
            ...OverlayStyles::list(),
            ...DataStyles::list(),
            ...NodeStyles::list(),
        ];
    }
}
