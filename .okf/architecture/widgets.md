---
type: Module
title: Widgets
description: "The widget object model: the Widget and Container contracts, slots, measure/arrange/paint/handle, dirty flags, factories, mail, and the widget set."
resource: src/Silk/Widgets/
tags: [silk, widgets, layout, mail]
status: draft
generated: { by: claude-sonnet/5.5, at: 2026-11-08T09:00:00Z }
sources:
  - id: contract
    resource: src/Silk/Contracts/Widget/Widget.php
    title: The Widget contract
  - id: base
    resource: src/Silk/Core/Tree/Widget.php
    title: The base every widget extends
  - id: tree
    resource: src/Silk/Core/Tree/Tree.php
    title: "Tree: layout, paint of the dirty region, hit testing"
  - id: spec
    resource: docs/superpowers/specs/2026-11-04-silk-design.md
    title: Section 5.2, widget object model; section 8, coverage
---

# The model

A widget is an object with an identity (`name()`, `path()`, `uuid()`), a parent, visibility, enabled and focusable flags, and four behaviours:[^contract]

| Method | Does |
|---|---|
| `measure(Size $available): Size` | Wanted size, bottom-up, cached per available size |
| `arrange(Rect $r)` | Takes its place, top-down |
| `paint(Painter $p)` | Describes itself to the [Painter](painter.md) in logical units; `render()` wraps it with the appear transition, opacity and overdraw |
| `handle(SilkEvent $e): bool` | Takes an [input](input.md) event; true stops it bubbling |

`Silk\\Core\\Tree\\Widget` is the base; `Silk\\Core\\Tree\\Container` is a widget with children, each in a `Slot(grow, align)` (main-axis grow share, cross-axis `Align`). Layouts live in `src/Silk/Core/Layout`: Column, Row, Grid, UniformGrid, Stack, Anchored, Border, SizeBox, Spacer, Switcher, WrapBox.

Setters store the value, mark the widget dirty and return `static`: `invalidateLayout()` (measure and arrange again up the ancestor chain) or `invalidatePaint()`. A widget draws within its bounds plus `OVERDRAW` (8 units, for shadows, the press squash and grown handles), or its own `paintExtent()`; the [Tree](hosts.md) repaints the union of where each dirty widget was and is now, and nothing else.[^tree] A widget may carry a tooltip, a context menu (a [Popup](overlays.md)), an appear/disappear `Transition` ([motion](motion.md)), a drop callback ([input](input.md)), and its own colour, metric and class overrides, which resolve above the theme ([looks](looks.md)).

# Creating widgets

Widgets are made through factory traits on containers (`Widgets\\Widgets`, `Factories/DataWidgets`, `EditingWidgets`, `OverlayWidgets`): `$column->button('save', 'Save')`, `label(name, text)`, `slider(name, min, max, value)`, `row(name, spacing)`, `backgroundBlur(name)`. Where Raven's widget has a Surface `TK*` twin, Silk takes the twin's creation method, constructor arguments, setter names and mail, so a toolkit-to-Silk swap is mechanical. App classes compose a subtree of widgets as they compose `TK*` primitives.

# Mail

A widget posts the mail its TK twin's driver posts (button clicked, value changed, text committed, and so on); where Surface's class does not depend on `TKPrimitive`, Silk posts it unchanged, otherwise Silk defines a twin in `Silk\\Contracts\\Mail` (NodeMoved, NodeLinked, TimelineEdited, DockChanged, ColorChanged, RowActivated, FileAccepted, TabClosed, ...). Mail is posted only for user action, never for programmatic setters.

# The set

Every Raven widget type, in `src/Silk/Widgets`, BlockEditor (the last of the 50) included:

| Kind | Widgets |
|---|---|
| Panels | Column, Row, Grid, UniformGrid, Stack, Fixed, SizeBox, Switcher, WrapBox, Border, ScrollBox, Splitter, Expander, TabView, DockArea, Island |
| Controls | Label, Button, Checkbox, Radio, Toggle, TextInput, Slider, SpinBox, ProgressBar, ScrollBar, Separator, Image, Rating, Pagination, Breadcrumb |
| Choosing | ComboBox, Dropdown, DropdownList, Menu, MenuBar, MenuItem, DatePicker, TimePicker, Calendar, ColorPicker |
| Data | ListView, TreeView, Table, TileView, FileBrowser, Timeline, NodeGraph |
| Programs | BlockEditor ([blocks](blocks.md)): Scratch-style blocks that snap into each other |
| Text | TextEditor (multi-line, syntax colours), Label markdown |
| Overlay widgets | Dialog, MessageBox, Toast and ToastQueue ([overlays](overlays.md)) |
| Window | HeaderBar |
| Surfaces | BackgroundBlur (Plain, Frosted, Glass, Native styles), Sketch (hands the app the engine for its own drawing) |

The [gallery](../runbooks/gallery-and-bench.md) shows every widget, working, on every theme; a built widget that is not in the gallery is not done.

[^contract]: src/Silk/Contracts/Widget/Widget.php, Container.php, Slot.php.
[^tree]: Tree::paintAll() and Widget::paintExtent().
