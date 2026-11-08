---
type: Module
title: Silk overview
description: A Venusian package of self-drawn widgets that look and move like Raven's, painting through whatever Surface rendering engine is plugged in.
resource: src/Silk/
tags: [silk, widgets, overview]
status: draft
generated: { by: claude-sonnet/5.5, at: 2026-11-08T11:00:00Z }
sources:
  - id: spec
    resource: docs/superpowers/specs/2026-11-04-silk-design.md
    title: Silk design (2026-11-04); the code has moved past some of it
  - id: composer
    resource: composer.json
    title: Root package and provider
  - id: agents
    resource: AGENTS.md
    title: The execution canon
---

# What it is

Silk is a module for Surface: a set of widget objects that hold their own state, lay themselves out, paint themselves through a `RenderingEngine` and take raw input, posting mail to the Venusian event loop.[^spec] They are to rendering engines what Surface's `TK*` primitives are to toolkits: a `TKButton` stores state and asks a toolkit to draw it, a Silk `Button` stores the same state and draws itself. Raven (Oscar Soirey) is the reference for look, motion constants and the widget set; it is not a dependency.

# Layers

```
app code ── composes ──▶ widgets        (src/Silk/Widgets, state, layout, paint, input, mail)
                            │
              Core ─────────┴───────── Looks
   tree, layout, springs,          palette, metrics, part looks,
   input routing, overlays         the 12 built-ins, glass material
                            │
                        Painter        (EnginePainter: logical units → engine calls)
                            │
        Surface RenderingEngine ── Velvet │ Metal │ OpenGL │ Vulkan │ SDL3
                            │
        Host: CanvasHost (inside a TKCanvas) or StagedHost (owns a staged window)
```

# Packages

Root `venusian/silk`, PSR-4 `Silk\\` → `src/Silk`, `0.11.x-dev`.[^composer] One package: the folders under `src/Silk` are not split packages and carry no `composer.json`.

| Folder | Holds |
|---|---|
| `contracts` | Widget, Container, Slot, Align; Host; SilkEvent, EventType; Look, Theme, Role, Metric, Material, State, SurfaceKind, PartLook; Painter, BlurLook, GlassLook; the mail classes; Translator |
| `core` | Tree, the widget and container bases, layout passes, springs and transitions, Router, FocusManager, drag sessions, overlays, the `silk` singleton and its provider |
| `hosts` | CanvasHost, StagedHost, the two input adapters, FrameLoop, FramePump |
| `looks` | LookManager, ThemedLook, StyleSheet, Palette, Metrics, Accent, GlassStyle, BuiltInThemes, `tokens.php` |
| `painter` | EnginePainter, ClipStack, EngineLowering, Snap, Surfaces, RavenPaint, RoundedPath, Icons, Knob |
| `text` | TextMeasurer, EditBuffer, Selection, caret geometry and blink, word breaks, the editor row model, syntax colouring, Markdown layout, Dates, the English translator |
| `widgets` | The widget classes (see [widgets](widgets.md)) |

Requires Surface's contracts, drawing, fonts, framebuffers, human-input, images, nuts-and-bolts and windows splits, and Voyager's contracts, io-pools and nuts-and-bolts.

# Container and config

`Silk\\Core\\Providers\\SilkServiceProvider` is the aggregate provider (found through `extra.venusian.providers`). It binds the `silk` singleton (alias `Silk`), `silk.looks` (the [LookManager](looks.md)) and `silk.translator`, publishes `config/silk.php` under the `silk-config` tag, and joins the Venusian loop through `FramePump::join()` when `event-loop` resolves.

`config/silk.php` keys: `look` (the look every tree starts with, `raven-dark`), `accent` (`auto`, `system`, `theme`, `custom`) and `accent_color`, `scale` (UI scale, 0.25..4, multiplied by the window scale), `reduced_motion`, `font.family` and `font.mono` (fontconfig families). There is no engine key: the engine is Surface's `drawing.default`, and a GPU engine is always a manual choice.

# Rules that govern every change

The execution canon in `AGENTS.md` binds Silk: Velvet's CPU work runs only when the engine is Velvet; one change covers every path (the four GPU engines, the extensions, the plain-PHP fallback); Silk describes what to draw and holds no per-pixel loops; a headless bench of the real gallery at scale 2 is the gate. See [Surface contract](surface-contract.md).

[^spec]: docs/superpowers/specs/2026-11-04-silk-design.md, sections 1 and 5.
[^composer]: composer.json.
