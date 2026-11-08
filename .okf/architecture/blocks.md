---
type: Module
title: Blocks

resource: src/Silk/Widgets/Blocks/
tags: [silk, widgets, blockeditor, blocks]
status: draft
generated: { by: claude-sonnet/5.5, at: 2026-11-08T09:00:00Z }
sources:
  - id: editor
    resource: src/Silk/Widgets/BlockEditor.php
    title: The widget
  - id: model
    resource: src/Silk/Widgets/Blocks/BlockModel.php
    title: The model
  - id: layout
    resource: src/Silk/Widgets/Blocks/BlockLayout.php
    title: Layout, hit tests, the drop-target search
  - id: tests
    resource: tests/Widgets/BlocksModelTest.php
    title: Model tests
---

# Parts

| Part | Does |
|---|---|
| `BlockModel` | The scripts without a screen: kinds, blocks, where each hangs, the rules, `save()` / `load()` (JSON), `duplicate()`, selection, glow, locked blocks, `revision()`.[^model] |
| `BlockKind` | A kind: name, label, shape, category, and the label read into words and slots (up to 12 slots, 6 sections).[^model] |
| `BlockLayout` | Sizes from text widths, places from sizes, the gap a carried stack opens, the palette by category, `blockAt`, `paletteAt`, `findTarget`.[^layout] |
| `BlockEditor` | The widget: paint, input, mail. Changes through its methods repaint; `model()` is for reading.[^editor] |

# The label

Words and slots: `%n` number, `%s` text, `%b` condition, `%r` any value, `%m` a choice in a menu (`setSlotOptions`); `{text}` right after a slot is its default (a number slot defaults to `0`); `|` separates the sections of a C block, each followed by a mouth. Other shapes ignore `|`. "repeat %n{10}", "if %b then|else", "%n + %n".[^model]

# Shapes and places

Six shapes: statement, hat (nothing above), cap (nothing under), C (mouths), reporter (round value), boolean (hexagon value). A block hangs under another (`Next`), in a mouth (`Body`, index = the mouth), in a value slot (`Input`, index = the slot), or is the top of a script at x, y.

Rules (`canAttach`): a value goes only into a slot that takes its shape (`%n`/`%s` a reporter, `%b` a boolean, `%r` either, `%m` none); a step goes under a block that has a next, or into a mouth; a hat only at the top; never into the block's own subtree. `attach` also: a value that was in the slot jumps out beside; a lone C block dropped into a chain wraps what was under that place; a stack that ends in a cap leaves what was under the place beside.[^model]

# Interaction

Palette on the left (210 units), canvas on the right with pan (middle or right drag, or Alt + left) and zoom (Ctrl + wheel). A drag takes the block with what is under it; the nearest place within 34 units opens a gap and shows its shadow, a slot that would take a value lights up; dropped on the palette it is deleted (a locked block comes back). A click selects (Ctrl or Shift adds) and posts `BlockEditorClicked` with the script's top; a drag on the background draws a selection rectangle; dragging a selected block moves the whole selection; Delete removes the selection; a click on a value slot types in it (the text is selected, a number slot keeps a number; Enter keeps it, Escape leaves it), a menu slot opens its choices; a right click posts `BlockEditorContext` and opens the built-in menu (Delete, Duplicate, Clean up, Select all; `setBuiltInMenu(false)` leaves it to the application). Blocks glide with springs to where the layout puts them; `setBlockGlow` makes a halo breathe around a running block (a ticker steps its clock).[^editor]

# Painting

A filled path per block (notch on top, tab under, a dome for a hat, a pill, a hexagon, C blocks around their mouths) in the category's colour (the one given with `setCategoryColor`, else one picked from the name for the look's light or dark), text dark or light by the colour's brightness, slots as white fields. Grid, palette and the open list use the look's roles, so the editor follows all 12 looks.[^editor]

# Tests

`BlocksModelTest` (label parser, attach and detach rules, save and load, layout, hit tests, the drop-target search, gap and shadow) and `BlockEditorTest` (every look, palette drag, snap, delete on the palette, selection, rubber band, group move, typing, menu slots, the menu, pan and zoom, glow).[^tests]

[^editor]: src/Silk/Widgets/BlockEditor.php.
[^model]: src/Silk/Widgets/Blocks/BlockModel.php, BlockKind.php.
[^layout]: src/Silk/Widgets/Blocks/BlockLayout.php.
[^tests]: tests/Widgets/BlocksModelTest.php, tests/Widgets/BlockEditorTest.php.
