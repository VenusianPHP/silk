<?php

namespace Silk\Looks\Styles;

use Silk\Contracts\Look\State;

/** RavenDark styles for Lane A's widgets, as [widget, class, part, state, props]; merged into RavenDark. */
final class OverlayStyles
{
    /** @return list<array{?string, ?string, string, ?\Silk\Contracts\Look\State, array<string, mixed>}> */
    public static function list(): array
    {
        return [
            // the island in a dark look: near black, a white rim at .085 (+.05 under the pointer), a tight shadow at
            // .30 and a lifting one at .26 + .16 open (island.c:410-418); 'Capsule', so the generic Body rows stay out
            ['Island', null, 'Capsule', null, ['background' => \Silk\Looks\Palette::parse('#08080A/252'), 'border' => new \Surface\NutsAndBolts\Color(1, 1, 1),
                'rim' => 0.085, 'rimHover' => 0.05, 'shadowColor' => new \Surface\NutsAndBolts\Color(0, 0, 0), 'tight' => 0.30, 'lift' => 0.26, 'liftOpen' => 0.16]],
            // a dropdown field is a button that holds a value: the button's Silk depth
            ['Dropdown', null, 'Body', null, ['shadow' => 3.0, 'shadowColor' => 'Shadow', 'shadowAlpha' => 1 / 3]],
            // Raven's combo field: a 7-unit chevron icon; disabled, the fill and the outline at half (combo_box.c:327-330, 386)
            ['Dropdown', null, 'Body', null, ['arrow' => 'icon']],
            ['Dropdown', null, 'Body', State::Disabled, ['backgroundAlpha' => 0.5, 'borderAlpha' => 0.5]],
            // Raven's flat scrollbar: a Track at 0.6 under the thumb (common.c:638-641)
            ['ScrollBar', null, 'Body', null, ['bars' => 'track']],
            // Raven's combo list: one unit from the edge, a sliding Selection highlight (combo_box.c:57-62, 147-159)
            ['DropdownList', null, 'Body', null, ['rows' => 'slide', 'listPad' => 1.0]],
            // Raven's menu (menu.c:243-287, 430-505): rows RowHeight + 2 from 4 in; a Selection highlight at radius 3 that
            // slides; the text 20 into the row; a check icon 8 at 10; a chevron icon 7, 10 from the right; the shortcut
            // 10 from the right in the text mixed 0.6 toward TextDisabled; separators 7 tall, the line 4 into the row
            ['Menu', null, 'Body', null, ['textX' => 20.0, 'checkX' => 10.0, 'checkSize' => 8.0, 'chevron' => 'icon', 'chevronRight' => 10.0,
                'shortcutRight' => 10.0, 'shortcutShrink' => 0.0, 'shortcutMix' => 0.6, 'highlightX' => 0.0, 'highlightY' => 0.0, 'openY' => 0.0, 'separatorMargin' => 8.0]],
            ['Menu', null, 'Highlight', null, ['background' => 'Selection', 'radius' => 3.0]],
            ['Menu', null, 'Highlight', State::Selected, ['background' => 'Selection', 'radius' => 3.0]],
            // Raven's popup surface: Popup, a 1-unit PopupBorder, PopupRadius; the shadow blur is the caller's (common.c:449-452)
            ['Popup', null, 'Body', null, ['background' => 'Popup', 'border' => 'PopupBorder', 'borderWidth' => 1.0, 'radius' => 'PopupRadius', 'shadowColor' => 'Shadow']],
            // Raven's tooltip: Tooltip, radius 4, a 1-unit PopupBorder, a shadow y+2 blur 8; text +8, +5; at the pointer +20 (window.c:216-242)
            ['Tooltip', null, 'Body', null, ['background' => 'Tooltip', 'border' => 'PopupBorder', 'borderWidth' => 1.0, 'radius' => 4.0, 'text' => 'TooltipText',
                'shadow' => 8.0, 'shadowColor' => 'Shadow', 'shadowDy' => 2.0, 'padX' => 8.0, 'padY' => 5.0, 'drop' => 20.0]],
        ];
    }
}
