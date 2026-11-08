<?php

namespace Silk\Looks\Styles;

use Silk\Contracts\Look\State;
use Surface\NutsAndBolts\Color;
use Silk\Looks\Palette;

/** The macOS family (Aqua), laid over the shared Raven styles; Raven 4a05d6f src/widgets/platform_looks.c. */
final class MacOSStyles
{
    /** @return list<array{?string, ?string, string, ?State, array<string, mixed>}> */
    public static function list(bool $dark): array
    {
        return [
            ...self::button('Button', $dark),
            // the pop-up button: the push button, the text before 32, two chevrons on an Accent square (combo_box.c:340-364)
            ...self::button('Dropdown', $dark),
            ['Dropdown', null, 'Body', null, ['arrow' => 'popup', 'arrowRoom' => 32.0]],
            ['Dropdown', null, 'Arrow', null, ['background' => 'Accent', 'text' => 'AccentText', 'radius' => 4.0]],
            ['Dropdown', null, 'Arrow', State::Disabled, ['background' => null, 'text' => 'TextDisabled']],
            ...self::field('TextInput'),
            ...self::field('SpinBox'),
            ...self::checkbox($dark),
            ...self::toggle($dark),
            ...self::slider($dark),
            ...self::tabView($dark),
            ...self::expander($dark),
            // the progress bar: a groove 6 high in the middle (progress_bar.c:34-43)
            ['ProgressBar', null, 'Body', null, ['size' => 6.0]],
            // the spin box: a stepper 15 wide cut in two; its chevrons in the text ink, the text when hot
            // (spin_box.c:83-108, 174-182, 199-200; platform_looks.c:312)
            ['SpinBox', null, 'Body', null, ['steppers' => 'stacked']],
            ['Steppers', null, 'Glyph', null, ['textInk' => $dark ? 0.55 : 0.5]],
            ['Steppers', null, 'Glyph', State::Hover, ['text' => 'Text']],
            // menus (menu.c:346-428; platform_looks.c:127-139, 312): the row under the pointer, and the row of an open
            // submenu, in the Accent with white text; the text 20 in; a check 9 at 11; a chevron 12 x 0.6, 16 from the
            // right; the shortcut 2 smaller, 12 from the right, in the text ink; separators 10 from the flyout's edges
            ['Menu', null, 'Body', null, ['textX' => 20.0, 'checkX' => 11.0, 'checkSize' => 9.0, 'chevron' => 'chevron', 'chevronRight' => 16.0,
                'shortcutRight' => 12.0, 'shortcutShrink' => 2.0, 'highlightX' => 1.0, 'highlightY' => 0.0, 'openY' => 1.0, 'separatorMargin' => 10.0]],
            ['Menu', null, 'Highlight', null, ['background' => 'Accent', 'radius' => 4.0]],
            ['Menu', null, 'Highlight', State::Selected, ['background' => 'Accent', 'radius' => 4.0]],
            ['Menu', null, 'Item', State::Hover, ['text' => 'AccentText']],
            ['Menu', null, 'Item', State::Selected, ['text' => 'AccentText']],
            ['Menu', null, 'Secondary', null, ['textInk' => $dark ? 0.55 : 0.5]],
            ['Menu', null, 'Secondary', State::Hover, ['text' => 'AccentText']],
            ['Menu', null, 'Secondary', State::Selected, ['text' => 'AccentText']],
            // the pop-up list: a menu, rows 5 in, the row under the pointer in the Accent, a check before the chosen
            // one (combo_box.c:57-62, 118-143; platform_looks.c:127-139)
            ['DropdownList', null, 'Body', null, ['rows' => 'menu', 'listPad' => 5.0, 'rowInset' => 5.0, 'lead' => 16.0, 'check' => 'before']],
            ['DropdownList', null, 'Item', null, ['radius' => 4.0]],
            ['DropdownList', null, 'Item', State::Hover, ['background' => 'Accent', 'text' => 'AccentText']],
            // overlay scrollbars: 7 then 9 wide over a Popup back (common.c:593-594, 600; graph scrollbar_overlay_thin/thick)
            ['ScrollBar', null, 'Body', null, ['bars' => 'overlay', 'thin' => 7.0, 'thick' => 9.0]],
            ['ScrollBar', null, 'Track', null, ['background' => 'Popup']],
            // menus: a deep shadow; tooltips: small, square-ish, 11 pt, padded 6 x 3 (platform_looks.c:244-250; window.c:132-134, 150-152)
            ['Popup', null, 'Body', null, ['popupShadow' => 24.0]],
            ['Tooltip', null, 'Body', null, ['radius' => 4.0, 'shadow' => 6.0, 'shadowDy' => 1.5, 'fontSize' => 11.0, 'padX' => 6.0, 'padY' => 3.0, 'lead' => 1.0, 'round' => 1.0, 'drop' => 22.0]],
        ];
    }

    /** The field of a text input or a spin box: rvn_platform_field_look (platform_looks.c:111-125), the focus ring (317-321). */
    private static function field(string $widget): array
    {
        return [
            // the field: Input, a ControlBorder hairline, radius min(CornerRadius, 4), text 5 in; focus is a ring
            // around it, three wide (platform_looks.c:111-125, 317-321; text_input.c:33)
            // sunk: a top edge of the border at 1.5 times its alpha (platform_looks.c:118)
            [$widget, null, 'Body', null, ['background' => 'Input', 'border' => 'ControlBorder', 'borderWidth' => 1.0, 'radius' => 4.0, 'textInset' => 5.0, 'focusCloses' => 0.0,
                'borderTop' => 'ControlBorder', 'borderTopAlpha' => 1.5]],
            [$widget, null, 'Body', State::Focused, ['border' => 'ControlBorder']],
            [$widget, null, 'Body', State::Disabled, ['backgroundAlpha' => 0.5, 'text' => 'TextDisabled']],
            [$widget, null, 'Ring', State::Focused, ['border' => 'Focus', 'borderWidth' => 3.0]],
        ];
    }

    /** rvn_platform_button_look on macOS: the Aqua push button (platform_looks.c:96-108). */
    private static function button(string $widget, bool $dark): array
    {
        return [
            // the push button: Control, a ControlBorder hairline, no hover change, no shadow; a lighter top edge in
            // the dark, a darker bottom edge in the light; disabled edges at 0.6 (platform_looks.c:96-108)
            [$widget, null, 'Body', null, ['background' => 'Control', 'border' => 'ControlBorder', 'borderWidth' => 1.0, 'shadow' => 0.0,
                ...($dark ? ['borderTop' => Palette::parse('#FFFFFF/38')] : ['borderBottom' => Palette::parse('#000000/64')])]],
            [$widget, null, 'Body', State::Hover, ['background' => 'Control']],
            [$widget, null, 'Body', State::Disabled, ['backgroundAlpha' => 0.5, 'borderAlpha' => 0.6, 'text' => 'TextDisabled']],
            // the default button: the accent, its edge the fill, lighter at the top (.25 white), darker at the bottom
            // (.15 black); disabled, a push button's (platform_looks.c:87-93)
            [$widget, 'primary', 'Body', null, ['background' => 'Accent', 'border' => 'Accent', 'borderWidth' => 1.0, 'text' => 'AccentText', 'lightTop' => 0.25, 'shadeBottom' => 0.15,
                'borderTop' => null, 'borderBottom' => null]],
            [$widget, 'primary', 'Body', State::Hover, ['background' => 'Accent', 'border' => 'Accent']],
            [$widget, 'primary', 'Body', State::Pressed, ['background' => 'AccentPressed', 'border' => 'AccentPressed']],
            [$widget, 'primary', 'Body', State::Disabled, ['background' => 'Control', 'backgroundAlpha' => 0.5, 'border' => 'ControlBorder', 'borderAlpha' => 0.6,
                'text' => 'TextDisabled', 'lightTop' => 0.0, 'shadeBottom' => 0.0,
                ...($dark ? ['borderTop' => Palette::parse('#FFFFFF/38')] : ['borderBottom' => Palette::parse('#000000/64')])]],
            // the flat button: borderless, nothing until pressed, the ink .15 (.1 in the light) (platform_looks.c:80-85)
            [$widget, 'flat', 'Body', null, ['background' => null, 'border' => null, 'borderWidth' => 0.0, 'borderTop' => null, 'borderBottom' => null]],
            [$widget, 'flat', 'Body', State::Hover, ['background' => null]],
            [$widget, 'flat', 'Body', State::Pressed, ['ink' => $dark ? 0.15 : 0.1]],
            [$widget, 'flat', 'Body', State::Disabled, ['background' => null, 'text' => 'TextDisabled']],
        ];
    }

    /** The check box and the radio button of macOS (checkbox.c:110-131, 143-155): parts, eased; off rows before on rows (last written wins). */
    private static function checkbox(bool $dark): array
    {
        $rows = [['Checkbox', null, 'Body', null, ['paint' => 'parts']]];
        // the box round 3.5, the radio round (checkbox.c:121)
        foreach (['Box' => 3.5, 'Radio' => 999.0] as $part => $radius) {
            array_push($rows,
                // off: a little white square, a hairline of the ink, darker at the bottom in the light
                ['Checkbox', null, $part, null, ['background' => $dark ? Palette::parse('#FFFFFF/48') : Palette::parse('#FFFFFF/255'), 'radius' => $radius,
                    'borderWidth' => 1.0, 'borderInk' => $dark ? 0.15 : 0.2, ...($dark ? [] : ['borderBottomInk' => 0.3])]],
                ['Checkbox', null, $part, State::Pressed, ['background' => 'ControlPressed']],
                // on: the accent, edge and all, its bottom 15% toward black; pressed AccentPressed
                ['Checkbox', 'on', $part, null, ['background' => 'Accent', 'border' => 'Accent', 'borderInk' => null, 'borderBottomInk' => null, 'shadeBottom' => 0.15]],
                ['Checkbox', 'on', $part, State::Pressed, ['background' => 'AccentPressed', 'border' => 'AccentPressed']],
                ['Checkbox', null, $part, State::Disabled, ['opacity' => 0.5]],
            );
        }

        return [...$rows,
            ['Checkbox', null, 'Mark', null, ['text' => 'AccentText', 'size' => 9.0]],
            ['Checkbox', null, 'Mark', State::Disabled, ['opacity' => 0.5]],
            // the radio's dot: AccentText, 6 (checkbox.c:150-155)
            ['Checkbox', null, 'Dot', null, ['background' => 'AccentText', 'size' => 6.0, 'radius' => 999.0]],
            ['Checkbox', null, 'Dot', State::Disabled, ['opacity' => 0.5]],
        ];
    }

    /** The switch of macOS (NSSwitch 38 x 22; checkbox.c:38, 156-180, 198-228): parts, off rows before on rows. */
    private static function toggle(bool $dark): array
    {
        return [
            ['Toggle', null, 'Body', null, ['paint' => 'parts', 'width' => 38.0, 'height' => 22.0]],
            // off: the ink, a hairline of it; on: Switch
            ['Toggle', null, 'Track', null, ['ink' => $dark ? 0.25 : 0.09, 'borderInk' => $dark ? 0.1 : 0.06, 'borderWidth' => 1.0, 'radius' => 999.0]],
            ['Toggle', 'on', 'Track', null, ['background' => 'Switch', 'ink' => null, 'borderInk' => null, 'borderWidth' => 0.0]],
            ['Toggle', null, 'Track', State::Disabled, ['opacity' => 0.5]],
            // the knob: white (grey off in the dark), 2 smaller than the track, a soft shadow, a hairline
            ['Toggle', null, 'Knob', null, ['background' => Palette::parse($dark ? '#CACACA' : '#FFFFFF'), 'inset' => 2.0, 'radius' => 999.0,
                'shadow' => 2.0, 'shadowColor' => Palette::parse('#000000/90'), 'border' => Palette::parse('#000000/20'), 'borderWidth' => 0.5]],
            ['Toggle', 'on', 'Knob', null, ['background' => Palette::parse('#FFFFFF')]],
            ['Toggle', null, 'Knob', State::Disabled, ['opacity' => 0.6]],
        ];
    }

    /** The slider of macOS: a thin track, the accent up to the value, a white knob with a soft shadow (slider.c:103-134). */
    private static function slider(bool $dark): array
    {
        $knob = $dark ? 202 / 255 : 1.0;

        return [
            ['Slider', null, 'Body', null, ['paint' => 'parts']],
            ['Slider', null, 'Track', null, ['background' => 'Track', 'size' => 4.0, 'radius' => 2.0]],
            ['Slider', null, 'Fill', null, ['background' => 'Accent', 'size' => 4.0, 'radius' => 2.0]],
            ['Slider', null, 'Track', State::Disabled, ['opacity' => 0.5]],
            ['Slider', null, 'Fill', State::Disabled, ['opacity' => 0.5]],
            // the knob (#CACACA in the dark), 2 smaller than the handle; pressed, 6% toward black (slider.c:118-127)
            ['Slider', null, 'Thumb', null, ['background' => new Color($knob, $knob, $knob), 'border' => Palette::parse('#000000/38'), 'borderWidth' => 0.5,
                'shadow' => 2.0, 'shadowColor' => Palette::parse('#000000/70'), 'inset' => 2.0, 'radius' => 999.0]],
            ['Slider', null, 'Thumb', State::Pressed, ['background' => new Color($knob * 0.94, $knob * 0.94, $knob * 0.94)]],
            ['Slider', null, 'Thumb', State::Disabled, ['opacity' => 0.6]],
        ];
    }

    /** The tabs of macOS: a segmented control (tab_view.c:376-412, 446-484). */
    private static function tabView(bool $dark): array
    {
        return [
            ['TabView', null, 'Body', null, ['paint' => 'segmented', 'height' => 22.0, 'inset' => 1.0]],
            ['TabView', null, 'Header', null, ['ink' => $dark ? 0.1 : 0.06, 'radius' => 6.0, 'borderInk' => 0.06, 'borderWidth' => 1.0]],
            // the raised pill: white, the ink .28 in the dark; a small shadow, a hairline in the light
            ['TabView', null, 'Indicator', null, [...($dark ? ['ink' => 0.28] : ['background' => Palette::parse('#FFFFFF')]), 'radius' => 5.0,
                'shadow' => 1.5, 'shadowColor' => Palette::parse($dark ? '#000000/90' : '#000000/46'), ...($dark ? [] : ['border' => Palette::parse('#000000/20'), 'borderWidth' => 0.5])]],
            ['TabView', null, 'Divider', null, ['ink' => 0.15]],
            // documents tabs: rounded CornerRadius + 1, ControlPressed when active, the ink .06 under the pointer; the close
            // button the ink .08 lit, .16 pressed (tab_view.c:398-408, 438-441)
            ['TabView', null, 'Tab', null, ['radiusGrow' => 1.0]],
            ['TabView', null, 'Tab', State::Selected, ['background' => 'ControlPressed']],
            ['TabView', null, 'Tab', State::Hover, ['ink' => 0.06]],
            ['TabView', null, 'Close', State::Hover, ['ink' => 0.08, 'radius' => 4.0]],
            ['TabView', null, 'Close', State::Pressed, ['ink' => 0.16, 'radius' => 4.0]],
            ['TabView', null, 'Tab', null, ['text' => 'Text']],
            ['TabView', null, 'Tab', State::Disabled, ['text' => 'TextDisabled']],
        ];
    }

    /** The expander of macOS: a disclosure one row high, its chevron in the secondary ink (expander.c:33, 209-222). */
    private static function expander(bool $dark): array
    {
        return [
            ['Expander', null, 'Body', null, ['paint' => 'disclosure', 'header' => 'RowHeight']],
            ['Expander', null, 'Arrow', null, ['textInk' => $dark ? 0.55 : 0.5]],
        ];
    }
}
