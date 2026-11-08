---
type: Module
title: Input
description: SilkEvent and the two adapters; the Router's bubbling, capture and hover path; focus; drag and drop.
resource: src/Silk/Core/Input/
tags: [silk, input, events, focus, drag]
status: draft
generated: { by: claude-sonnet/5.5, at: 2026-11-08T04:30:00Z }
sources:
  - id: event
    resource: src/Silk/Contracts/Input/SilkEvent.php
    title: SilkEvent and EventType
  - id: router
    resource: src/Silk/Core/Input/Router.php
    title: Bubbling, capture, hover
  - id: focus
    resource: src/Silk/Core/Input/FocusManager.php
    title: Tab order and the focus ring
  - id: drag
    resource: src/Silk/Core/Drag/DragSession.php
    title: In-app drag
---

# One event type

`SilkEvent` carries a type, position (logical units), button, click count, key, modifiers, wheel deltas with a precise flag and scroll phase, text and edit commands. `EventType` cases: MouseMove, MouseDown, MouseUp, Wheel, Enter, Leave, KeyDown, KeyUp, Text, Edit, FocusGained, FocusLost.[^event]

# Adapters

* `CanvasInputAdapter` maps TKCanvas view mail (CanvasPressed, CanvasWheeled, KeyPressed, TextCommitted, EditCommanded, FocusGained, ...) to SilkEvent.
* `StagedInputAdapter` maps Surface's ordered staged-window input mail (StagedPressed, StagedKeyPressed, ...) the same way. Silk never synthesises events from polled input.

Hosts queue events (`Host::enqueue()`) and the [frame loop](hosts.md) drains them before anything else.

# Routing

`Router::dispatch(SilkEvent)` bubbles an event from the target widget to its ancestors, stopping when a handler returns true or at a popup root; it owns mouse capture (a press captures until release) and the hover path (Enter and Leave from path diffs). Popups are hit top-down before the root; a dialog's veil takes every event under it ([overlays](overlays.md)). The Router also arms long press, opens context menus, shows the tooltip and starts drags.[^router]

# Focus

`FocusManager`: Tab order is tree order; the focus ring shows only after Tab and is cleared by any mouse press.[^focus]

# Drag and drop

`Router::beginDrag(source, DragPayload, x, y)` starts a `DragSession`. The target is the nearest enabled widget at or above the one under the pointer that has a drop callback; it gets Leave, Enter and Move, and its answer says whether it would take the drop; release drops where taken; Escape or losing focus cancels. The chip of the label follows the pointer and the target's frame lands on a BOUNCY spring while it would take the drop.[^drag]

[^event]: src/Silk/Contracts/Input/.
[^router]: src/Silk/Core/Input/Router.php.
[^focus]: Class docblock of FocusManager.
[^drag]: Class docblock of DragSession.
