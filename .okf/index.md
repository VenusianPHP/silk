---
okf_version: "0.2"
---

# venusian/silk

* [Overview](architecture/overview.md) - Self-drawn widgets for Surface: packages, how Silk sits on Surface, the layers.
* [Surface contract](architecture/surface-contract.md) - What Silk may ask of Surface and what it never does: it describes, the drivers draw.

# Architecture

* [Widgets](architecture/widgets.md) - The object model: widget and container, slots, measure/arrange/paint/handle, dirty flags, factories, mail.
* [Hosts and the frame loop](architecture/hosts.md) - CanvasHost, StagedHost, FrameLoop, FramePump: one frame, idle cost zero.
* [Input](architecture/input.md) - SilkEvent, the two adapters, Router (bubbling, capture, hover), focus, drag.
* [Overlays](architecture/overlays.md) - Popups, tooltips, dialogs and toasts drawn inside the host, above the root.
* [Motion](architecture/motion.md) - Springs and their four presets, transitions, look blending, reduced motion.
* [Looks](architecture/looks.md) - Looks, themes, tokens: the 12 built-ins, roles and metrics, the glass material, accent.
* [Blocks](architecture/blocks.md) - BlockEditor: the model (kinds, labels, attach rules), the layout and hit tests, the widget, its mail and tests.
* [Painter](architecture/painter.md) - EnginePainter: logical units to device pixels, culling, what it records, glass() and shadows.

# Runbooks

* [Gallery and bench](runbooks/gallery-and-bench.md) - examples/gallery.php, the bench and golden scripts, how to run them and what they hold.
