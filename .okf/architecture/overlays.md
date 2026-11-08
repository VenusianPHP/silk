---
type: Module
title: Overlays
description: Popups, tooltips, dialogs, toasts and drag chips drawn in a layer inside the host, above the root, clamped to the host's bounds.
resource: src/Silk/Core/Overlay/
tags: [silk, overlay, popup, tooltip, dialog]
status: draft
generated: { by: claude-sonnet/5.5, at: 2026-11-08T04:30:00Z }
sources:
  - id: layer
    resource: src/Silk/Core/Overlay/OverlayLayer.php
    title: The popups of one tree
  - id: popup
    resource: src/Silk/Core/Overlay/Popup.php
    title: A popup
  - id: tooltip
    resource: src/Silk/Core/Overlay/Tooltip.php
    title: Tooltip
  - id: dialog
    resource: src/Silk/Widgets/Dialog.php
    title: Dialog
---

# Model

Menus, context menus, combo lists, pickers, tooltips, dialogs and toasts are drawn by the host's own tree in an overlay layer above the root, clamped to the host's bounds (a canvas host's menu cannot leave the canvas). No child OS windows are needed.[^layer]

* `OverlayLayer` holds the tree's popups in push order, the last on top. Popups are hit top-down before the root; closing popups paint but are never hit.
* `Popup` is a column drawn in the layer. It grows out of its anchor point on a BOUNCY [spring](motion.md): scale 0.72 + 0.28 × shown, opacity min(1, shown × 1.8); once closed it keeps painting without input until shown reaches 0. `Placement` says where it sits against its anchor: Below, Above, Right, Left, AtPoint.[^popup]
* `Tooltip` is passive (never hit or focused): text + 16 wide, 4-radius box in the Tooltip role with a shadow, at the pointer +12, +20, flipped above when there is no room, BOUNCY in, opacity min(1, shown × 2).[^tooltip]
* `Dialog` is a modal popup centred over a veil (black 0.42 × shown), card radius 10, shadow y+6 blur 28, scale 0.9 + 0.1 × shown, rising from 60 units; the veil takes every event under it. `MessageBox` is a dialog with a message.[^dialog]
* `Toast` and `ToastQueue` rise 14 units as they show (SNAPPY) and wait in sequence or stack; a hidden toast lets the pointer through.
* The drag chip and the target frame of an in-app [drag](input.md) paint in the same layer.

# Looks and glass

A popup of a glass look, and a macOS 26 look's popups, are painted as glass (`Popup` checks `material()->isGlass()` and `liquid()`): they go through `Painter::glass()` like any pane (see [painter](painter.md)); a tooltip of a glass look is a drop of glass in the tooltip colour. Their shadow rides in the glass look, so rounded corners meet it.

[^layer]: Class docblock of OverlayLayer.
[^popup]: Class docblock of Popup, Placement.
[^tooltip]: Class docblock of Tooltip.
[^dialog]: Class docblock of Dialog.
