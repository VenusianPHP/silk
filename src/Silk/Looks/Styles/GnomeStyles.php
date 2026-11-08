<?php

namespace Silk\Looks\Styles;

use Silk\Contracts\Look\State;
use Surface\NutsAndBolts\Color;
use Silk\Looks\Palette;

/** The GNOME family (libadwaita), laid over the shared Raven styles; Raven 4a05d6f src/widgets/platform_looks.c. */
final class GnomeStyles
{
    /** @return list<array{?string, ?string, string, ?State, array<string, mixed>}> */
    public static function list(bool $dark = false): array
    {
        return [
            ...self::button('Button'),
            // the drop-down: the button, the text before 32, a chevron 18 from the right, 14 x 0.6 (combo_box.c:340-370)
            ...self::button('Dropdown'),
            ['Dropdown', null, 'Body', null, ['arrow' => 'chevron', 'arrowRoom' => 32.0, 'chevronRight' => 18.0, 'chevronSize' => 14.0]],
            ...self::field('TextInput'),
            ...self::field('SpinBox'),
            ...self::checkbox(),
            ...self::toggle(),
            ...self::slider(),
            ...self::tabView($dark),
            ...self::expander(),
            // the progress bar: a groove 8 high in the middle (progress_bar.c:34-43)
            ['ProgressBar', null, 'Body', null, ['size' => 8.0]],
            // the spin box: minus then plus at the end, flat buttons (ink .07 hot, .16 pressed), radius CornerRadius,
            // their glyphs the button text (spin_box.c:99-101, 186-197; platform_looks.c:169-178)
            ['SpinBox', null, 'Body', null, ['steppers' => 'sideBySide']],
            ['Steppers', null, 'Arrow', null, ['radius' => 'CornerRadius', 'text' => 'Text']],
            ['Steppers', null, 'Arrow', State::Hover, ['ink' => 0.07]],
            ['Steppers', null, 'Arrow', State::Pressed, ['ink' => 0.16]],
            ['Steppers', null, 'Arrow', State::Disabled, ['text' => 'Text', 'textAlpha' => 0.5]],
            // popover menus (menu.c:346-428; platform_looks.c:196-212, 314): ink .07 under the pointer, .1 on an open
            // submenu's row, CornerRadius; the text 12 in (36 with checks); a check 9 at 20; a chevron 12 x 0.6, 16 from
            // the right; the shortcut 2 smaller, 12 from the right, in ink .55; separators 6 from the flyout's edges
            ['Menu', null, 'Body', null, ['textX' => 12.0, 'checkColumn' => 24.0, 'checkX' => 20.0, 'checkSize' => 9.0, 'chevron' => 'chevron', 'chevronRight' => 16.0,
                'shortcutRight' => 12.0, 'shortcutShrink' => 2.0, 'highlightX' => 2.0, 'highlightY' => 1.0, 'openY' => 1.0, 'separatorMargin' => 6.0]],
            ['Menu', null, 'Highlight', null, ['ink' => 0.07, 'radius' => 'CornerRadius']],
            ['Menu', null, 'Highlight', State::Selected, ['ink' => 0.1, 'radius' => 'CornerRadius']],
            ['Menu', null, 'Secondary', null, ['textInk' => 0.55]],
            // the drop-down list: a popover menu, rows 6 in, ink .07 under the pointer and on the chosen row, .16
            // pressed, a check after the chosen one (combo_box.c:57-62, 118-143; platform_looks.c:196-212)
            ['DropdownList', null, 'Body', null, ['rows' => 'menu', 'listPad' => 6.0, 'rowInset' => 6.0, 'check' => 'after']],
            ['DropdownList', null, 'Item', null, ['radius' => 'CornerRadius', 'text' => 'Text']],
            ['DropdownList', null, 'Item', State::Hover, ['ink' => 0.07]],
            ['DropdownList', null, 'Item', State::Selected, ['ink' => 0.07]],
            ['DropdownList', null, 'Item', State::Pressed, ['ink' => 0.16]],
            // overlay scrollbars: 3 then 8 wide over a Window back (common.c:593-594, 600; graph scrollbar_overlay_thin/thick)
            ['ScrollBar', null, 'Body', null, ['bars' => 'overlay', 'thin' => 3.0, 'thick' => 8.0]],
            ['ScrollBar', null, 'Track', null, ['background' => 'Window']],
            // the header bar: round buttons 24 wide, gap 12, margin 8 (header_bar.c:113-117); the close button is
            // ControlHovered/ControlPressed like the others, not red (header_bar.c:314-319); a Border line under it (411-412)
            ['HeaderBar', null, 'Buttons', null, ['variant' => 'round', 'size' => 24.0, 'gap' => 12.0, 'margin' => 8.0]],
            ['HeaderBar', null, 'Line', null, ['border' => 'Border']],
            ['HeaderBar', null, 'CloseButton', State::Hover, ['background' => 'ControlHovered', 'text' => 'Text']],
            ['HeaderBar', null, 'CloseButton', State::Pressed, ['background' => 'ControlPressed', 'text' => 'Text']],
            // popovers: a border, large corners, shadow 20; tooltips: no border, CornerRadius, no shadow, 13 pt, padded 10 x 6
            // (platform_looks.c:251-257; window.c:132-134, 150-152)
            ['Popup', null, 'Body', null, ['popupShadow' => 20.0]],
            ['Tooltip', null, 'Body', null, ['border' => null, 'borderWidth' => 0.0, 'radius' => 'CornerRadius', 'shadow' => 0.0, 'fontSize' => 13.0,
                'padX' => 10.0, 'padY' => 6.0, 'lead' => 1.0, 'round' => 1.0, 'drop' => 22.0]],
        ];
    }

    /** The field of a text input or a spin box: rvn_platform_field_look (platform_looks.c:181-194). */
    private static function field(string $widget): array
    {
        return [
            // the entry: Input, no border; focused, a Focus ring two wide inside its edge; text 9 in; insensitive at
            // half strength (platform_looks.c:181-194; text_input.c:33)
            [$widget, null, 'Body', null, ['background' => 'Input', 'border' => null, 'borderWidth' => 0.0, 'textInset' => 9.0, 'focusCloses' => 0.0]],
            [$widget, null, 'Body', State::Focused, ['border' => 'Focus', 'borderWidth' => 2.0]],
            [$widget, null, 'Body', State::Disabled, ['backgroundAlpha' => 0.5, 'text' => 'Text', 'textAlpha' => 0.5]],
        ];
    }

    /** rvn_platform_button_look on GNOME: the Adwaita button (platform_looks.c:160-178). */
    private static function button(string $widget): array
    {
        return [
            // the button: no border, no shadow; insensitive at half strength (platform_looks.c:160-178)
            [$widget, null, 'Body', null, ['border' => null, 'borderWidth' => 0.0, 'shadow' => 0.0]],
            [$widget, null, 'Body', State::Disabled, ['background' => 'Control', 'backgroundAlpha' => 0.5, 'text' => 'Text', 'textAlpha' => 0.5]],
            // .suggested-action: the accent, AccentText (platform_looks.c:164-167)
            [$widget, 'primary', 'Body', null, ['background' => 'Accent', 'text' => 'AccentText']],
            [$widget, 'primary', 'Body', State::Hover, ['background' => 'AccentHovered']],
            [$widget, 'primary', 'Body', State::Pressed, ['background' => 'AccentPressed']],
            [$widget, 'primary', 'Body', State::Disabled, ['background' => 'Accent', 'backgroundAlpha' => 0.5, 'text' => 'AccentText', 'textAlpha' => 0.5]],
            // .flat: the ink .07 under the pointer, .16 pressed (platform_looks.c:168-170)
            [$widget, 'flat', 'Body', null, ['background' => null, 'shadow' => 0.0]],
            [$widget, 'flat', 'Body', State::Hover, ['ink' => 0.07]],
            [$widget, 'flat', 'Body', State::Pressed, ['ink' => 0.16]],
            [$widget, 'flat', 'Body', State::Disabled, ['background' => null, 'ink' => null]],
        ];
    }

    /** The check box and the radio button of GNOME (checkbox.c:42-44, 132-155): 14 inside a round square of 20; parts, eased. */
    private static function checkbox(): array
    {
        $rows = [['Checkbox', null, 'Body', null, ['paint' => 'parts', 'markGrow' => 6.0]]];
        // the box round 6, the radio round (checkbox.c:134)
        foreach (['Box' => 6.0, 'Radio' => 999.0] as $part => $radius) {
            array_push($rows,
                // off: no fill, a ring of the ink 2 wide; ink .05 under the pointer, .1 pressed
                ['Checkbox', null, $part, null, ['radius' => $radius, 'borderInk' => 0.2, 'borderWidth' => 2.0]],
                ['Checkbox', null, $part, State::Hover, ['ink' => 0.05]],
                ['Checkbox', null, $part, State::Pressed, ['ink' => 0.1]],
                // on: the accent, no ring
                ['Checkbox', 'on', $part, null, ['background' => 'Accent', 'ink' => null, 'borderInk' => null, 'borderWidth' => 0.0]],
                ['Checkbox', 'on', $part, State::Hover, ['background' => 'AccentHovered', 'ink' => null]],
                ['Checkbox', 'on', $part, State::Pressed, ['background' => 'AccentPressed', 'ink' => null]],
                ['Checkbox', null, $part, State::Disabled, ['opacity' => 0.5]],
            );
        }

        return [...$rows,
            ['Checkbox', null, 'Mark', null, ['text' => 'AccentText', 'size' => 11.0]],
            ['Checkbox', null, 'Mark', State::Disabled, ['opacity' => 0.5]],
            // the radio's dot: AccentText, 8 (checkbox.c:150-155)
            ['Checkbox', null, 'Dot', null, ['background' => 'AccentText', 'size' => 8.0, 'radius' => 999.0]],
            ['Checkbox', null, 'Dot', State::Disabled, ['opacity' => 0.5]],
        ];
    }

    /** The switch of GNOME (48 x 26; checkbox.c:39, 156-180, 198-228): parts, off rows before on rows. */
    private static function toggle(): array
    {
        return [
            ['Toggle', null, 'Body', null, ['paint' => 'parts', 'width' => 48.0, 'height' => 26.0, 'stretch' => 0.0]],
            // off: the ink .15, .2 under the pointer, .3 pressed; on: Switch, AccentPressed pressed
            ['Toggle', null, 'Track', null, ['ink' => 0.15, 'radius' => 999.0]],
            ['Toggle', null, 'Track', State::Hover, ['ink' => 0.2]],
            ['Toggle', null, 'Track', State::Pressed, ['ink' => 0.3]],
            ['Toggle', 'on', 'Track', null, ['background' => 'Switch', 'ink' => null]],
            ['Toggle', 'on', 'Track', State::Hover, ['background' => 'Switch', 'ink' => null]],
            ['Toggle', 'on', 'Track', State::Pressed, ['background' => 'AccentPressed', 'ink' => null]],
            ['Toggle', null, 'Track', State::Disabled, ['opacity' => 0.5]],
            // the knob: white, 6 smaller than the track, a soft shadow, no hairline
            ['Toggle', null, 'Knob', null, ['background' => Palette::parse('#FFFFFF'), 'inset' => 6.0, 'radius' => 999.0,
                'shadow' => 2.0, 'shadowColor' => Palette::parse('#000000/90'), 'border' => Palette::parse('#000000/20'), 'borderWidth' => 0.0]],
            ['Toggle', null, 'Knob', State::Disabled, ['opacity' => 0.6]],
        ];
    }

    /** The slider of GNOME: a thin track, the accent up to the value, a white knob with a soft shadow (slider.c:103-134). */
    private static function slider(): array
    {
        $knob = 1.0;

        return [
            ['Slider', null, 'Body', null, ['paint' => 'parts']],
            ['Slider', null, 'Track', null, ['background' => 'Track', 'size' => 6.0, 'radius' => 3.0]],
            ['Slider', null, 'Fill', null, ['background' => 'Accent', 'size' => 6.0, 'radius' => 3.0]],
            ['Slider', null, 'Track', State::Disabled, ['opacity' => 0.5]],
            ['Slider', null, 'Fill', State::Disabled, ['opacity' => 0.5]],
            // the knob; pressed, 6% toward black (slider.c:118-127)
            ['Slider', null, 'Thumb', null, ['background' => new Color($knob, $knob, $knob), 'border' => Palette::parse('#000000/26'), 'borderWidth' => 1.0,
                'shadow' => 4.0, 'shadowColor' => Palette::parse('#000000/51'), 'inset' => 0.0, 'radius' => 999.0]],
            ['Slider', null, 'Thumb', State::Pressed, ['background' => new Color($knob * 0.94, $knob * 0.94, $knob * 0.94)]],
            ['Slider', null, 'Thumb', State::Disabled, ['opacity' => 0.6]],
        ];
    }

    /** The tabs of GNOME: a segmented control, the titles not active a little dimmer (tab_view.c:376-412, 446-484). */
    private static function tabView(bool $dark): array
    {
        return [
            ['TabView', null, 'Body', null, ['paint' => 'segmented', 'inset' => 3.0]],
            ['TabView', null, 'Header', null, ['ink' => 0.1, 'radius' => 9.0]],
            ['TabView', null, 'Indicator', null, [...($dark ? ['ink' => 0.16] : ['background' => Palette::parse('#FFFFFF')]), 'radius' => 6.0,
                'shadow' => 3.0, 'shadowColor' => Palette::parse($dark ? '#000000/90' : '#000000/46')]],
            ['TabView', null, 'Hover', null, ['ink' => 0.05, 'radius' => 6.0]],
            // documents tabs: rounded CornerRadius + 2, ControlPressed when active, the ink .06 under the pointer; the close
            // button the ink .08 lit, .16 pressed (tab_view.c:398-408, 438-441)
            ['TabView', null, 'Tab', null, ['radiusGrow' => 2.0]],
            ['TabView', null, 'Tab', State::Selected, ['background' => 'ControlPressed']],
            ['TabView', null, 'Tab', State::Hover, ['ink' => 0.06]],
            ['TabView', null, 'Close', State::Hover, ['ink' => 0.08, 'radius' => 9.0]],
            ['TabView', null, 'Close', State::Pressed, ['ink' => 0.16, 'radius' => 9.0]],
            ['TabView', null, 'Tab', null, ['textInk' => 0.75]],
            ['TabView', null, 'Tab', State::Hover, ['text' => 'Text', 'textInk' => null]],
            ['TabView', null, 'Tab', State::Selected, ['text' => 'Text', 'textInk' => null]],
            ['TabView', null, 'Tab', State::Disabled, ['text' => 'TextDisabled', 'textInk' => null]],
        ];
    }

    /** The expander of GNOME: an expander row 50 high, a card, the content a fainter card under it (expander.c:31-32, 38-92). */
    private static function expander(): array
    {
        return [
            ['Expander', null, 'Body', null, ['paint' => 'card', 'header' => 50.0]],
            ['Expander', null, 'Header', null, ['background' => 'Panel', 'border' => 'Border', 'borderWidth' => 1.0, 'radius' => 'PopupRadius', 'text' => 'Text']],
            ['Expander', null, 'Header', State::Disabled, ['text' => 'TextDisabled']],
            ['Expander', null, 'Content', null, ['background' => 'Panel', 'backgroundAlpha' => 0.7, 'border' => 'Border', 'borderWidth' => 1.0, 'radius' => 'PopupRadius']],
            // the chevron's button: lit under the pointer
            ['Expander', null, 'Arrow', null, ['radius' => 'PopupRadius', 'text' => 'Text']],
            ['Expander', null, 'Arrow', State::Hover, ['background' => 'RowHovered']],
        ];
    }
}
