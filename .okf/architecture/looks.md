---
type: Module
title: Looks, themes and tokens
description: The look contract (roles, metrics, part looks, material), the 12 built-in themes, the style sheet, accent derivation, and how a glass look differs.
resource: src/Silk/Looks/
tags: [silk, looks, themes, tokens, glass]
status: draft
generated: { by: claude-sonnet/5.5, at: 2026-11-08T04:30:00Z }
sources:
  - id: contract
    resource: src/Silk/Contracts/Look/Look.php
    title: The Look contract
  - id: builtin
    resource: src/Silk/Looks/BuiltInThemes.php
    title: The 12 built-ins
  - id: tokens
    resource: src/Silk/Looks/tokens.php
    title: Generated from Neo4j db silk by scripts/export-theme-tokens.py
  - id: glass-style
    resource: src/Silk/Looks/GlassStyle.php
    title: Glass per surface kind
  - id: sheet
    resource: src/Silk/Looks/StyleSheet.php
    title: Style specificity
---

# Contract

A `Look` answers `color(Role)` (36 roles), `metric(Metric)` (20 metrics, including GlassFrost, GlassRefraction, GlassRim, GlassSaturation, GlassShadow), `font(FontRole)`, `motion()` (springy or smooth, see [motion](motion.md)), `part(widget, part, State, classes): PartLook`, `dark()`, `darkTwin()`, `material()` and `liquid()`.[^contract] States: Normal, Hover, Pressed, Focused, Checked, Disabled, Selected. Widgets resolve what they paint from the look; a widget's own `setColor`, `setMetric` and classes resolve above it ([widgets](widgets.md)). Only the text colour inherits down the tree; `LookScope` gives a subtree a look of its own.

# The 12 built-ins

`BuiltInThemes::NAMES`: `raven-dark` (the default), `raven-light`, `raven-high-contrast-dark`, `raven-high-contrast-light`, `macos-dark`, `macos-light`, `gnome-dark`, `gnome-light`, `raven-glass-dark`, `raven-glass-light`, `macos-glass-dark`, `macos-glass-light`.[^builtin] Their roles, metrics and styles are the generated `tokens.php` (`scripts/export-theme-tokens.py` writes it from the Neo4j database `silk`; do not edit it by hand). A theme is a PHP array (`look`, `accent`, `colors`, `metrics`, `font`, `motion`, `material`, `syntax`, `styles`) or a `Theme` class; `app('silk.looks')` is the `LookManager`: `extend(name, fn)` registers a theme, `use(name)` switches the active one and updates every watched tree live.

# Style sheet

Styles are `[widget, class, part, state, props]`; a null widget, class or state matches any. Specificity: type 1, class 2, state 2; ties go to the last written.[^sheet] `ThemedLook` is a look built from a theme laid over its base (palette, metrics, style sheet).

# Accent

`Accent` derives the roles that follow the accent colour per family (Raven theme.c:230-299). `config/silk.php` `accent`: `auto` (the system's when the toolkit reports one), `system`, `theme`, `custom`.

# The glass material

`Material`: Solid, Glass (clear: barely tinted and blurred, a rim that bends like a lens), FrostedGlass, TintedGlass (frosted and coloured by the accent, stronger on what floats higher). A look whose material is glass paints its panels, controls, popups and bars as glass: `GlassStyle::of(look, SurfaceKind, hover, press, material)` answers the glass a kind of surface is in that look (tint, rim, frost, refraction, saturation, glow, bevel, shadow), tuned by the Glass* metrics. `SurfaceKind`: Control, Accent, Input, Bar, Panel, Popup, Lens. `Surfaces::paint` paints a surface as the look's glass, faded and shadowless when disabled. The glass is drawn by `Painter::glass()` ([painter](painter.md)); glass looks need the backdrop under each pane, which is Surface's job.

[^contract]: src/Silk/Contracts/Look/.
[^builtin]: src/Silk/Looks/BuiltInThemes.php.
[^sheet]: Class docblock of StyleSheet.
