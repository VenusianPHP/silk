---
type: Runbook
title: Gallery and bench
description: examples/gallery.php shows every widget on every theme; bench-gallery, bench-timeline and golden-frames are the scripts that gate drawing work.
resource: scripts/
tags: [silk, gallery, bench, golden, gate]
status: draft
generated: { by: claude-sonnet/5.5, at: 2026-11-08T04:30:00Z }
sources:
  - id: gallery
    resource: examples/gallery.php
    title: The gallery
  - id: bench
    resource: scripts/bench-gallery.php
    title: The execution gate
  - id: timeline
    resource: scripts/bench-timeline.php
    title: Timeline paint cost
  - id: golden
    resource: scripts/golden-frames.php
    title: Golden frames for Velvet
---

# The gallery

`examples/gallery.php` is one app with a tab for each family of widgets (Controls, Text, Lists and scrolling, Data, Overlays, Pickers, Layout, Pictures, Glass, Docking, Nodes, Blocks, Editor, Timeline), a Theme dropdown that switches between the built-in [looks](../architecture/looks.md) while it runs, and the Glass tab's BackgroundBlur panes over a striped, busy backdrop with a blur-radius slider. Every widget that is built must be in the gallery, working, on every theme.

Run it as an app: `php rocket silk-gallery` from the devbox app (launching a window needs Angel's OK).

# bench-gallery

The execution gate: the real gallery, the real `FrameLoop` and `Host`, at scale 2 (900 × 640 points, 1800 × 1280 pixels), on Velvet with the extensions and on Metal offscreen. No window opens. The clock is virtual (each frame 1/60 s later) so springs and timers behave as at 60 fps, and the times are the real cost of each `FrameLoop::frame()`. A scenario runs its input, then frames until three in a row paint nothing, up to 900.

```
zhp scripts/bench-gallery.php            both engines
zhp scripts/bench-gallery.php velvet     one engine (also: metal, spy)
BENCH_TABS=0 zhp scripts/bench-gallery.php   skip every tab's cold frame
```

`spy` records only: the CPU cost of recording a frame, which is all a GPU engine leaves to the CPU. It prints each tab's frames, painted frames, average, p95 and max, then the Nodes tab (pointer pulses, a node drag) with a pass/fail against 16.7 ms, and idle checks.

# bench-timeline

`zhp scripts/bench-timeline.php`: Timeline paint cost by stage (widget, + lowering through EnginePainter, + Velvet, + Metal) for 5,000 clips with the whole length in view, the same clips in a 20 s view, and 4 items.

# golden-frames

`zhp scripts/golden-frames.php record [--keep] [filter]` and `check [filter]` hold Velvet's pixels byte for byte (sha256 of 134 frames in `tests/Fixtures/golden-frames.json`): Surface's GpuParity scenes, anti-aliased and hard, and the real gallery, every tab settled, in raven-dark, raven-light and raven-glass-dark at scales 1 and 2 (on Velvet with ext-rasterize and ext-fb). The gallery's clock reads are pinned so the frames are deterministic. `GOLDEN_SURFACE` loads another Surface checkout (for a baseline); `GOLDEN_RAW` is where a failing frame's raw RGBA8 goes. It is for changes that must not move a pixel; a change that moves pixels on purpose (the glass rewrite) means re-recording the affected frames.

# Running them here

* Edit and commit on the SSD; pull to `~/Development/dev` and run gallery, bench and GPU jobs only from there. A GPU job takes `/tmp/silk-gpu.lock` and runs alone.
* Scratch output goes to `/private/tmp`, never the SSD.
* Look at the frames you render; engines agreeing with each other is not a check ([surface contract](../architecture/surface-contract.md)).

# Tests

Pest tests under `tests/` mirror `src/Silk`: Contracts, Core, Files, Hosts, Looks, Painter, Text, Widgets, with shared helpers and the recording `SpyEngine`, `SpyPainter` and `SpyLoop` in `tests/Support`.
