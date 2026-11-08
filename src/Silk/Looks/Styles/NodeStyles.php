<?php

namespace Silk\Looks\Styles;

use Silk\Contracts\Look\State;
use Surface\NutsAndBolts\Color;

/**
 * The NodeGraph's own colours, as [widget, class, part, state, props]: Raven's rvn_ng_base (node_graph.c:1045-1180)
 * gives each preset colours of its own; the widget derives the rest from the theme's roles. Classes are the
 * canvas presets (CanvasLook::classes).
 */
final class NodeStyles
{
    /** @return list<array{?string, ?string, string, ?State, array<string, mixed>}> */
    public static function list(): array
    {
        return [
            // a pin of no type: the text mixed toward this grey (rvn_ng_pin_color, node_graph.c:313-318)
            ['NodeGraph', null, 'Pin', null, ['anyType' => Color::hex('#8A93A6')]],
            // the text of the canvas presets: Modern's, and the night presets' (node_graph.c:1210-1211)
            ['NodeGraph', 'modern-dark', 'Canvas', null, ['text' => Color::hex('#E4E4E8')]],
            ['NodeGraph', 'modern-light', 'Canvas', null, ['text' => Color::hex('#18181B')]],
            ['NodeGraph', 'blueprint', 'Canvas', null, ['text' => Color::hex('#E4E4E8')]],
            ['NodeGraph', 'blender', 'Canvas', null, ['text' => Color::hex('#E4E4E8')]],
            ['NodeGraph', 'neon', 'Canvas', null, ['text' => Color::hex('#E4E4E8')]],
            // the constants the paint mixes in: Blender's grey tone and quiet wires (:1432, :1692-1693), Blueprint's
            // white exec pins (:1276), the dark ink on a light header or badge (:1388, :1479)
            ['NodeGraph', 'blender', 'Node', null, ['tone' => Color::hex('#3A3A3A')]],
            ['NodeGraph', 'blender', 'Link', null, ['quiet' => Color::hex('#9C9C9C')]],
            ['NodeGraph', 'blueprint', 'Pin', null, ['exec' => Color::hex('#F2F2F2')]],
            ['NodeGraph', null, 'Header', null, ['darkInk' => Color::hex('#15161A')]],
            ['NodeGraph', null, 'Indicator', null, ['darkInk' => Color::hex('#14151A')]],
            // the canvas (node_graph.c:1050-1061)
            ['NodeGraph', 'modern-dark', 'Canvas', null, ['background' => Color::hex('#161618')]],
            ['NodeGraph', 'modern-light', 'Canvas', null, ['background' => Color::hex('#F1F1F3')]],
            ['NodeGraph', 'blueprint', 'Canvas', null, ['background' => Color::hex('#1C1F24')]],
            ['NodeGraph', 'blender', 'Canvas', null, ['background' => Color::hex('#1D1D1D'), 'border' => Color::hex('#111111')]],
            ['NodeGraph', 'neon', 'Canvas', null, ['background' => Color::hex('#06060B'), 'border' => Color::rgba(0x8B, 0x5C, 0xF6, 70 / 255)]],
            // the grid (:1064-1071)
            ['NodeGraph', 'blueprint', 'Grid', null, ['text' => Color::hex('#C8D4E8')]],
            ['NodeGraph', 'neon', 'Grid', null, ['text' => Color::hex('#A78BFA')]],
            // the nodes (:1087-1121)
            ['NodeGraph', 'modern-dark', 'Node', null, ['background' => Color::hex('#232327')]],
            ['NodeGraph', 'blueprint', 'Node', null, ['background' => Color::rgba(0x11, 0x13, 0x17, 240 / 255)]],
            ['NodeGraph', 'blender', 'Node', null, ['background' => Color::hex('#303030'), 'border' => Color::hex('#161616')]],
            ['NodeGraph', 'neon', 'Node', null, ['background' => Color::rgba(0x0B, 0x0B, 0x14, 238 / 255)]],
            ['NodeGraph', 'blueprint', 'Node', State::Selected, ['border' => Color::hex('#F5B342')]],
            ['NodeGraph', 'blender', 'Node', State::Selected, ['border' => Color::hex('#EDEDED')]],
            // the pins (:1139)
            ['NodeGraph', 'blender', 'Pin', null, ['border' => Color::hex('#101010')]],
            // the badge (:1171)
            ['NodeGraph', 'neon', 'Indicator', null, ['background' => Color::hex('#EC4899')]],
        ];
    }
}
