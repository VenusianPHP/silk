---
type: Module
title: Hosts and the frame loop
description: CanvasHost and StagedHost bind a Silk tree to an engine and a window; FrameLoop runs one frame; FramePump joins the Venusian loop. An idle tree costs nothing.
resource: src/Silk/Hosts/
tags: [silk, host, frame-loop]
status: draft
generated: { by: claude-sonnet/5.5, at: 2026-11-08T04:30:00Z }
sources:
  - id: loop
    resource: src/Silk/Hosts/FrameLoop.php
    title: One frame
  - id: host
    resource: src/Silk/Hosts/Host.php
    title: The host base
  - id: canvas
    resource: src/Silk/Hosts/CanvasHost.php
    title: A tree inside a TKCanvas
  - id: staged
    resource: src/Silk/Hosts/StagedHost.php
    title: A tree that owns a staged window
---

# The frame

`FrameLoop::frame(Host)` does, in order:[^loop]

1. Drain the host's queued [SilkEvents](input.md) and dispatch each through the host's Router.
2. Step the [springs](motion.md): `FrameClock` turns the real gap since the last frame (bounded to 0.001..0.25 s) into equal steps of at most 1/30 s, and `Tree::advance()` takes each; a tree with no live spring forgets the clock.
3. Lay the tree out to the host's viewport (up to `Tree::MAX_PASSES` = 4 measure/arrange passes while something re-dirties, as wrapped text does).
4. If nothing needs paint and nothing wants paint (a moving spring), return false: no layout work beyond the check, no paint, no present.
5. Otherwise, inside `engine->frame()`, `Tree::paintAll(new EnginePainter(...))`: the [Painter](painter.md) records only the dirty region; the engine's own damage diffing limits the pixels touched. Then `host->present()`.

`FramePump` is the Silk resource on the Venusian loop: it joins when `event-loop` resolves and runs one frame per attached host per tick.

# Hosts

* **CanvasHost** wraps a `TKCanvas`: it watches the pointer, keys and size, converts points to pixels with the canvas scale, and draws through any engine made for the canvas as its output. An engine that draws into the canvas's own framebuffer is made anew when the canvas resizes; a GPU engine on the view's lent layer follows the size itself in `begin()`, and a GPU engine borrowing the canvas is never remade on resize. The canvas presents either. Overlays stay inside the canvas. Native and Silk widgets can sit side by side in one toolkit window.[^canvas]
* **StagedHost** owns a staged window and the input mail its stager posts while it watches input; the stager's session must have joined the event loop or nothing pumps it.[^staged]
* `Host::bind(engine, pointsW, pointsH, pixelScale)` hands a host its engine and size (the bench binds one by hand, with no window).

Surface's `drawing.default` picks the engine (Velvet unless the app says otherwise); `SILK_ENGINE` overrides it for one run. Nothing picks a GPU engine automatically.

# Costs and gates

Idle frames settle (three in a row painting nothing is the bench's definition of settled). The [bench](../runbooks/gallery-and-bench.md) times `FrameLoop::frame()` on the real gallery.

[^loop]: src/Silk/Hosts/FrameLoop.php and FrameClock.php.
[^canvas]: Class docblock of CanvasHost.
[^staged]: Class docblock of StagedHost.
