<?php

namespace Silk\Looks\Styles;

use Silk\Contracts\Look\State;
use Surface\NutsAndBolts\Color;

/** The shared styles of Lane B's M2 widgets, as [widget, class, part, state, props]; every theme starts from them. */
final class DataStyles
{
    /** @return list<array{?string, ?string, string, ?State, array<string, mixed>}> */
    public static function list(): array
    {
        return [
            // Raven list_view.c: an Input field with a Border outline, Focus while focused
            ['ListView', null, 'Body', null, ['background' => 'Input', 'border' => 'Border']],
            ['ListView', null, 'Body', State::Focused, ['border' => 'Focus']],
            // Raven table.c: the same field under a Header strip
            ['Table', null, 'Body', null, ['background' => 'Input', 'border' => 'Border']],
            ['Table', null, 'Body', State::Focused, ['border' => 'Focus']],
            // Raven tree_view.c: the same field
            ['TreeView', null, 'Body', null, ['background' => 'Input', 'border' => 'Border']],
            ['TreeView', null, 'Body', State::Focused, ['border' => 'Focus']],
            // Raven menu.c:766-823: the strip in Header, a radius-3 mark of Selection under the open title, HeaderHovered under the pointer
            ['MenuBar', null, 'Body', null, ['background' => 'Header']],
            ['MenuBar', null, 'Title', State::Hover, ['background' => 'HeaderHovered', 'radius' => 3.0]],
            ['MenuBar', null, 'Title', State::Pressed, ['background' => 'Selection', 'radius' => 3.0]],
            // Raven date_picker.c:525-536: a button field (Control, Hovered, Pressed while open; ControlBorder; faded to 0.5 disabled)
            ['DatePicker', null, 'Body', null, ['background' => 'Control', 'border' => 'ControlBorder', 'radius' => 'CornerRadius', 'borderWidth' => 'BorderWidth']],
            ['DatePicker', null, 'Body', State::Hover, ['background' => 'ControlHovered']],
            ['DatePicker', null, 'Body', State::Pressed, ['background' => 'ControlPressed']],
            ['DatePicker', null, 'Body', State::Disabled, ['backgroundAlpha' => 0.5, 'borderAlpha' => 0.5]],
            // Raven header_bar.c: the strip and its line, the buttons by variant (flat 46 wide; a look may set 'round' 24/12/8),
            // the macOS lights (13, gap 8, margin 14; 0xFF5F57 0xFEBC2E 0x28C840, an edge of black 0.18; glyphs black 0.6),
            // the flat close button's red (0xE5484D, pressed 0xB3262B, glyph white) and the notch's dark tab
            ['HeaderBar', null, 'Body', null, ['background' => 'Header', 'extraHeight' => 10.0]],
            ['HeaderBar', null, 'Line', null, ['border' => 'Separator']],
            ['HeaderBar', null, 'Buttons', null, ['variant' => 'flat', 'size' => 46.0, 'gap' => 0.0, 'margin' => 0.0]],
            ['HeaderBar', null, 'Lights', null, ['size' => 13.0, 'gap' => 8.0, 'margin' => 14.0]],
            ['HeaderBar', null, 'CloseLight', null, ['background' => Color::hex('#ff5f57'), 'border' => new Color(0.0, 0.0, 0.0, 0.18), 'borderWidth' => 0.5]],
            ['HeaderBar', null, 'MinimizeLight', null, ['background' => Color::hex('#febc2e'), 'border' => new Color(0.0, 0.0, 0.0, 0.18), 'borderWidth' => 0.5]],
            ['HeaderBar', null, 'MaximizeLight', null, ['background' => Color::hex('#28c840'), 'border' => new Color(0.0, 0.0, 0.0, 0.18), 'borderWidth' => 0.5]],
            ['HeaderBar', null, 'LightGlyph', null, ['text' => new Color(0.0, 0.0, 0.0, 0.6)]],
            ['HeaderBar', null, 'CaptionButton', State::Hover, ['background' => 'ControlHovered']],
            ['HeaderBar', null, 'CaptionButton', State::Pressed, ['background' => 'ControlPressed']],
            ['HeaderBar', null, 'CloseButton', State::Hover, ['background' => Color::hex('#e5484d'), 'text' => new Color(1.0, 1.0, 1.0)]],
            ['HeaderBar', null, 'CloseButton', State::Pressed, ['background' => Color::hex('#b3262b'), 'text' => new Color(1.0, 1.0, 1.0)]],
            ['HeaderBar', null, 'Notch', null, ['background' => new Color(0.04, 0.04, 0.05), 'mix' => 0.9, 'border' => new Color(1.0, 1.0, 1.0, 0.07),
                'text' => new Color(0.94, 0.94, 0.96), 'shadowColor' => new Color(0.0, 0.0, 0.0, 0.28)]],
            ['HeaderBar', null, 'Notch', State::Disabled, ['mix' => 0.585, 'text' => new Color(0.94, 0.94, 0.96, 0.6), 'shadowColor' => new Color(0.0, 0.0, 0.0, 0.12)]],
            // Raven rating.c:27: the star size; a theme may change it
            ['Rating', null, 'Body', null, ['starSize' => 18.0]],
            // Raven tile_view.c:60-64: the tiles' sizes, a theme may change them
            ['TileView', null, 'Body', null, ['tileWidth' => 112.0, 'tileHeight' => 112.0, 'iconSize' => 64.0, 'spacing' => 8.0]],
            // Raven color_picker.c: the picker's own colours and sizes, the same in every Raven theme; a theme may override them
            ['ColorPicker', null, 'Body', null, [
                'width' => 252.0, 'square' => 150.0,                              // color_picker.c:522
                'strip' => 16.0, 'gap' => 10.0, 'swatch' => 20.0, 'swatchGap' => 6.0, // :24-26, :497-498
                'preview' => 46.0, 'modeWidth' => 58.0, 'fieldGap' => 6.0,        // :529, :541
                'squareRadius' => 9.0, 'previewRadius' => 8.0, 'swatchRadius' => 6.0, // :613, :628, :656
                'checkCell' => 8.0, 'popupWidth' => 252.0, 'popupPadding' => 12.0, // :28, :420, :416
            ]],
            ['ColorPicker', null, 'Edge', null, ['border' => 'ControlBorder', 'borderAlpha' => 0.7, 'borderWidth' => 1.0]],        // :616, :634
            ['ColorPicker', null, 'SwatchEdge', null, ['border' => 'ControlBorder', 'borderAlpha' => 0.8, 'borderWidth' => 1.0]],  // :656
            ['ColorPicker', null, 'Knob', null, ['background' => new Color(1.0, 1.0, 1.0), 'shadow' => 5.0,
                'shadowColor' => new Color(0.0, 0.0, 0.0, 0.4), 'size' => 10.0, 'held' => 12.0]],                                    // :566-572
            ['ColorPicker', null, 'Ring', null, ['border' => new Color(1.0, 1.0, 1.0), 'borderWidth' => 2.5, 'shadow' => 6.0,
                'shadowColor' => new Color(0.0, 0.0, 0.0, 0.45), 'size' => 16.0, 'held' => 20.0]],                                   // :616-622
            ['ColorPicker', null, 'CheckerA', null, ['background' => Color::rgb(215, 215, 215)]],                                   // :179
            ['ColorPicker', null, 'CheckerB', null, ['background' => Color::rgb(150, 150, 150)]],                                   // :179
            // the popup-mode swatch: a frame of the theme around the colour, like a button (color_picker.c:588-597)
            ['ColorPicker', null, 'Button', null, ['background' => 'Control', 'border' => 'ControlBorder', 'borderWidth' => 1.0]],
            ['ColorPicker', null, 'Button', State::Hover, ['background' => 'ControlHovered']],
            // the light along a folder icon's lip (file_browser.c:776: rvn_rgba(1, 1, 1, 0.25))
            ['FileBrowser', null, 'FolderShine', null, ['background' => new Color(1.0, 1.0, 1.0, 0.25)]],
            // Timeline (timeline.c:622-732, rvn_tl_base): the hard values of each canvas preset, by class; what
            // Raven derives from the theme's roles stays in Timeline::base
            ['Timeline', 'modern-dark', 'Canvas', null, ['background' => Color::hex('#18181B')]],                             // :630
            ['Timeline', 'modern-light', 'Canvas', null, ['background' => Color::hex('#F4F4F5')]],
            ['Timeline', 'blueprint', 'Canvas', null, ['background' => Color::hex('#1C1F24'), 'border' => new Color(0.0, 0.0, 0.0, 0.7)]],
            ['Timeline', 'blender', 'Canvas', null, ['background' => Color::hex('#1F1F1F'), 'border' => Color::hex('#111111')]],
            ['Timeline', 'neon', 'Canvas', null, ['background' => Color::hex('#06060B'), 'border' => Color::rgba(0x8B, 0x5C, 0xF6, 70 / 255)]],
            ['Timeline', 'modern-dark', 'Ruler', null, ['background' => Color::hex('#1E1E22')]],                            // :641-647
            ['Timeline', 'modern-light', 'Ruler', null, ['background' => Color::hex('#FFFFFF')]],
            ['Timeline', 'blueprint', 'Ruler', null, ['background' => Color::hex('#15171B'), 'border' => new Color(0.0, 0.0, 0.0, 0.8), 'text' => Color::hex('#C9CED8')]],
            ['Timeline', 'blender', 'Ruler', null, ['background' => Color::hex('#232323'), 'border' => Color::hex('#161616'), 'text' => Color::hex('#D6D6D6')]],
            ['Timeline', 'neon', 'Ruler', null, ['background' => Color::hex('#0B0B14'), 'border' => Color::rgba(0x8B, 0x5C, 0xF6, 60 / 255), 'text' => Color::hex('#D8D4FF')]],
            ['Timeline', 'modern-dark', 'Header', null, ['background' => Color::hex('#1E1E22')]],
            ['Timeline', 'modern-light', 'Header', null, ['background' => Color::hex('#FFFFFF')]],
            ['Timeline', 'blueprint', 'Header', null, ['background' => Color::hex('#15171B'), 'border' => new Color(0.0, 0.0, 0.0, 0.8), 'text' => Color::hex('#C9CED8')]],
            ['Timeline', 'blender', 'Header', null, ['background' => Color::hex('#2B2B2B'), 'border' => Color::hex('#161616'), 'text' => Color::hex('#D6D6D6')]],
            ['Timeline', 'neon', 'Header', null, ['background' => Color::hex('#0B0B14'), 'border' => Color::rgba(0x8B, 0x5C, 0xF6, 60 / 255), 'text' => Color::hex('#D8D4FF')]],
            ['Timeline', 'blender', 'Track', null, ['background' => Color::rgba(255, 255, 255, 6 / 255), 'border' => Color::hex('#151515')]],   // :654-656
            ['Timeline', 'neon', 'Track', null, ['border' => Color::rgba(0x8B, 0x5C, 0xF6, 36 / 255), 'none' => 'background']],
            ['Timeline', 'blueprint', 'Track', null, ['background' => Color::rgba(255, 255, 255, 5 / 255), 'border' => new Color(0.0, 0.0, 0.0, 0.6)]],
            ['Timeline', null, 'Grid', null, ['shape' => 'Lines']],                                                          // :660-665
            ['Timeline', null, 'Grid', null, ['opacity' => 0.13]],
            ['Timeline', 'raven-dark', 'Grid', null, ['opacity' => 0.11]],
            ['Timeline', 'macos-dark', 'Grid', null, ['opacity' => 0.11]],
            ['Timeline', 'gnome-dark', 'Grid', null, ['opacity' => 0.11]],
            ['Timeline', 'modern', 'Grid', null, ['opacity' => 0.07]],
            ['Timeline', 'blueprint', 'Grid', null, ['text' => Color::hex('#C8D4E8'), 'opacity' => 0.1]],
            ['Timeline', 'blender', 'Grid', null, ['text' => Color::hex('#000000'), 'opacity' => 0.35]],
            ['Timeline', 'neon', 'Grid', null, ['text' => Color::hex('#A78BFA'), 'opacity' => 0.14]],
            ['Timeline', null, 'Clip', null, ['opacity' => 1.0, 'borderWidth' => 1.0, 'shadowColor' => new Color(0.0, 0.0, 0.0, 0.3),
                'shape' => 'Tint', 'radius' => 6.0]],                                                                            // :668-680
            ['Timeline', 'macos', 'Clip', null, ['shape' => 'Solid', 'radius' => 6.0, 'none' => 'borderWidth']],
            ['Timeline', 'gnome', 'Clip', null, ['shape' => 'Tint', 'radius' => 8.0]],
            ['Timeline', 'windows', 'Clip', null, ['shape' => 'Tint', 'radius' => 4.0]],
            ['Timeline', 'modern', 'Clip', null, ['shape' => 'Tint', 'radius' => 9.0, 'none' => 'borderWidth', 'shadow' => 6.0]],
            ['Timeline', 'modern-dark', 'Clip', null, ['shadowColor' => new Color(0.0, 0.0, 0.0, 0.35)]],
            ['Timeline', 'modern-light', 'Clip', null, ['shadowColor' => new Color(0.0, 0.0, 0.0, 0.08)]],
            ['Timeline', 'blueprint', 'Clip', null, ['shape' => 'Gradient', 'radius' => 4.0, 'border' => new Color(0.0, 0.0, 0.0, 0.7)]],
            ['Timeline', 'blender', 'Clip', null, ['shape' => 'Solid', 'radius' => 3.0, 'border' => Color::hex('#0E0E0E'), 'text' => Color::hex('#EDEDED')]],
            ['Timeline', 'neon', 'Clip', null, ['shape' => 'Outline', 'radius' => 8.0, 'borderWidth' => 1.5, 'glow' => 0.5]],
            ['Timeline', 'neon', 'Clip', State::Hover, ['glow' => 0.8]],                                                      // :681
            ['Timeline', null, 'Clip', State::Selected, ['borderWidth' => 1.5, 'glow' => 0.3]],                                // :682-689
            ['Timeline', 'raven-dark', 'Clip', State::Selected, ['border' => Color::rgba(255, 255, 255, 235 / 255)]],
            ['Timeline', 'macos-dark', 'Clip', State::Selected, ['border' => Color::rgba(255, 255, 255, 235 / 255)]],
            ['Timeline', 'gnome-dark', 'Clip', State::Selected, ['border' => Color::rgba(255, 255, 255, 235 / 255)]],
            ['Timeline', 'modern-dark', 'Clip', State::Selected, ['border' => Color::rgba(255, 255, 255, 235 / 255)]],
            ['Timeline', 'blender', 'Clip', State::Selected, ['border' => Color::hex('#FFA733'), 'none' => 'glow']],
            ['Timeline', 'blueprint', 'Clip', State::Selected, ['border' => Color::hex('#F5B342'), 'glow' => 0.3]],
            ['Timeline', 'neon', 'Clip', State::Selected, ['border' => Color::hex('#FFFFFF'), 'borderWidth' => 2.0, 'glow' => 1.0]],
            ['Timeline', null, 'Key', null, ['shape' => 'Diamond', 'border' => new Color(0.0, 0.0, 0.0, 0.55)]],              // :692-704
            ['Timeline', 'modern', 'Key', null, ['shape' => 'Circle', 'none' => 'border']],
            ['Timeline', 'blender', 'Key', null, ['background' => Color::hex('#DADADA'), 'border' => Color::hex('#0E0E0E'), 'size' => 4.5]],
            ['Timeline', 'neon', 'Key', null, ['shape' => 'Ring', 'glow' => 0.7, 'none' => 'border']],
            // selected: white on a dark look and the night presets, else the text (Timeline::base) (:700-703)
            ['Timeline', 'raven-dark', 'Key', State::Selected, ['border' => Color::hex('#FFFFFF')]],
            ['Timeline', 'macos-dark', 'Key', State::Selected, ['border' => Color::hex('#FFFFFF')]],
            ['Timeline', 'gnome-dark', 'Key', State::Selected, ['border' => Color::hex('#FFFFFF')]],
            ['Timeline', 'modern-dark', 'Key', State::Selected, ['border' => Color::hex('#FFFFFF')]],
            ['Timeline', 'blueprint', 'Key', State::Selected, ['border' => Color::hex('#FFFFFF')]],
            ['Timeline', 'neon', 'Key', State::Selected, ['border' => Color::hex('#FFFFFF')]],
            ['Timeline', 'blender', 'Key', State::Selected, ['background' => Color::hex('#FFA733'), 'border' => Color::hex('#0E0E0E')]],
            ['Timeline', 'neon', 'Key', State::Selected, ['glow' => 1.0]],
            ['Timeline', null, 'Marker', null, ['radius' => 4.0]],                                                           // :706-710
            ['Timeline', 'blender', 'Marker', null, ['radius' => 2.0]],
            ['Timeline', 'neon', 'Marker', null, ['glow' => 0.6]],
            ['Timeline', null, 'Playhead', null, ['size' => 1.0]],                                                           // :712-720
            ['Timeline', 'modern', 'Playhead', null, ['size' => 2.0]],
            ['Timeline', 'blueprint', 'Playhead', null, ['background' => Color::hex('#FF5A4E'), 'size' => 1.0, 'glow' => 0.4]],
            ['Timeline', 'blender', 'Playhead', null, ['background' => Color::hex('#4772B3'), 'size' => 2.0]],
            ['Timeline', 'neon', 'Playhead', null, ['background' => Color::hex('#EC4899'), 'size' => 1.5, 'glow' => 1.0]],
            // text that reads on a light fill (rvn_tl_ink_on, timeline.c:757); Blender's clips lean to its grey (:770)
            ['Timeline', null, 'InkDark', null, ['background' => Color::hex('#15161A')]],
            ['Timeline', 'blender', 'ClipTone', null, ['background' => Color::hex('#4A4A4A')]],
            // the colours given to tracks that have none (timeline.c:165, g_tl_palette)
            ['Timeline', null, 'Palette0', null, ['background' => Color::hex('#0A84FF')]],
            ['Timeline', null, 'Palette1', null, ['background' => Color::hex('#FF9F0A')]],
            ['Timeline', null, 'Palette2', null, ['background' => Color::hex('#30D158')]],
            ['Timeline', null, 'Palette3', null, ['background' => Color::hex('#BF5AF2')]],
            ['Timeline', null, 'Palette4', null, ['background' => Color::hex('#FF375F')]],
            ['Timeline', null, 'Palette5', null, ['background' => Color::hex('#64D2FF')]],
            ['Timeline', null, 'Palette6', null, ['background' => Color::hex('#FFD60A')]],
            ['Timeline', null, 'Palette7', null, ['background' => Color::hex('#AC8E68')]],
        ];
    }
}
