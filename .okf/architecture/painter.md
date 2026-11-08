---
type: Module
title: Painter
description: "EnginePainter turns widgets' logical-unit drawing into RenderingEngine calls: snapping, clipping, culling, opacity, and glass(), blur() and shadows."
resource: src/Silk/Painter/
tags: [silk, painter, glass, shadow]
status: draft
generated: { by: claude-sonnet/5.5, at: 2026-11-08T04:30:00Z }
sources:
  - id: contract
    resource: src/Silk/Contracts/Painter/Painter.php
    title: The Painter contract
  - id: engine
    resource: src/Silk/Painter/EnginePainter.php
    title: EnginePainter
  - id: lowering
    resource: src/Silk/Painter/EngineLowering.php
    title: Silk's primitives on the engine's UI ops
  - id: glass
    resource: ../surface/.okf/architecture/drawing.md
    title: Surface's glass()
---

# Contract

`Painter` (`Silk\\Contracts\\Painter`): `rect(Rect, fill, radii, border, borderWidth)`, `gradient`, `fill`/`stroke` of a Path, `layer` (a group drawn apart and laid down at an opacity, optionally filtered), `shadow`, `blur`, `glass`, `line`, `triangle`, `image`, `text`, `sketch` (the app draws with the engine in a rect), the clip, opacity and transform stacks, `scale()` and `viewport()`.[^contract] Widgets paint in logical units and know nothing of the engine.

# EnginePainter

Used inside `engine->frame()`; one is made per frame by the [frame loop](hosts.md). It maps through the pushed transforms, scales to device pixels (window scale × UI scale), snaps rect edges with `floor(v × scale + 0.5)` (`Snap`), clamps strokes to at least one pixel and radii to half the short side, keeps Silk's own clip stack (each push intersects, `ClipStack`), multiplies the opacity stack into colours, and **culls**: a shape whose box lies more than `CULL_MARGIN` (48 units) outside the viewport, or misses the clip, never reaches the engine.[^engine] It records:

| Painter call | Engine call |
|---|---|
| `rect` | `roundRect` (per-corner radii, inside border) through `EngineLowering`; square rects use rect fills |
| `shadow` | `shadow` (radius, blur, offset) |
| `glass`, `blur` | `glass()` with a Surface `Glass` look, see below |
| `line` | one capsule contour (no engine line op yet) |
| `triangle`, `fill`, `stroke`, `gradient` | polygon, path, stroke and gradient fills |
| `image` | `image()`, rounded and tinted when asked |
| `text` | `text()` with the face from `FontSpec` (TrueType through Surface's fonts) |
| `layer` | `layer()` |
| `sketch` | hands the app the engine and a device rect |

Silk holds no per-pixel loop here: every verb above is drawn by Surface's drivers ([contract](surface-contract.md)).

# glass(), blur() and shadows

`Painter::blur(Rect, radii, BlurLook)` and `glass(Rect, radii, GlassLook)` both become one `RenderingEngine::glass()` with a `Surface\\Contracts\\Drawing\\Effects\\Glass` look in device pixels: blur radius (capped at 96 logical units × scale), tint, saturation, light, rim, bevel, refraction, grain, frost. `blur()` is the pane of BackgroundBlur's Plain and Frosted styles (radius, saturation, grain, tint, light); `glass()` is Raven's slab: its wide frost blur (22 device px), bent at the rim by the refraction across the bevel, lit by the rim colour, frost taken from the look. The engine reads what is under the pane, blurs it down and up a pyramid (a pane shares a capture with others only while nothing is drawn into the area it reads), and draws the pane in one pass over its rounded box.

The pane's **shadow** belongs to the engine: Painter passes `shadow` and `shadowColor` in the `Glass` look and the engine paints it after the backdrop is read and before the pane, so the pane does not blur its own shadow and its rounded corners meet the shadow with nothing cut out. A pane faded below full opacity would show a shadow under it, so that one keeps four clipped strips around its box (`shadowAround`). The hairline of a blur pane is an ordinary rect outline drawn over it. See Surface's [drawing](../../../surface/.okf/architecture/drawing.md) for the pyramid, grouping and per-engine details.

[^contract]: src/Silk/Contracts/Painter/Painter.php, BlurLook.php, GlassLook.php.
[^engine]: Class docblock and constants of EnginePainter.
