---
type: Module
title: Motion
description: Damped springs in closed form with four presets, appear transitions, look blending on a theme switch, and reduced motion.
resource: src/Silk/Core/Motion/
tags: [silk, motion, springs]
status: draft
generated: { by: claude-sonnet/5.5, at: 2026-11-08T16:45:00Z }
sources:
  - id: spring
    resource: src/Silk/Core/Motion/Spring.php
    title: Spring
  - id: presets
    resource: src/Silk/Core/Motion/SpringPreset.php
    title: The four presets
  - id: clock
    resource: src/Silk/Core/Motion/FrameClock.php
    title: Frame time
  - id: look-blend
    resource: src/Silk/Core/Motion/LookBlend.php
    title: A theme switch on its way
---

# Springs

Every animated value is a damped oscillator (value, velocity, target) stepped with the exact solution, so it does not depend on the frame rate; retargeting is free.[^spring] A spring is at rest when |value − target| ≤ 0.0015 and |velocity| ≤ 0.02. A new spring starts on its target (no animation on appearance).

| Preset | Period (s) | Damping | Used for |
|---|---|---|---|
| Snappy | 0.16 | 0.90 | hover, highlights, caret glide, press down |
| Smooth | 0.30 | 1.00 | scrolling, fades, sizes, scrollbar fade |
| Bouncy | 0.38 | 0.52 | switches, popups, press release, tooltips |
| Wobbly | 0.50 | 0.32 | fly-ins, drops |

A look's `Motion` is `Springy` (Raven's) or `Smooth` (the platform families: press release takes Snappy, nothing squashes, a tooltip fades Smooth — popups still grow out on Bouncy, drops and the node-wire sway stay Wobbly).

# Stepping

`FrameClock` turns the real gap since the last frame (0.001..0.25 s) into equal steps of at most 1/30 s; `Tree::advance(dt)` steps every moving spring, marks its widget for paint and reports whether anything still moves. After `advance()`, `Tree::needsPaint()` is how the [frame loop](hosts.md) keeps painting while a spring moves, and stops when none does.

# Transitions

`Transition` kinds: Fade, Pop, Zoom, FlyUp, FlyDown, FlyLeft, FlyRight, Drop, SlideLeft, SlideRight. A widget's `appear()`/disappear transition runs as an `Appear` (a 0..1 spring) applied after paint, so layout never moves; `paintExtent()` grows to cover it ([widgets](widgets.md)).

# Theme switches

`Tree::setLook()` starts a `LookBlend`: colours go from the old look to the new by the spring's value; metrics, fonts and part numbers are the new look's at once, because they move layout. With reduced motion (`silk.reduced_motion`, set on the tree by the host) every spring lands at once and a switch is assigned outright.

[^spring]: Class docblock of Spring (Raven anim.c).
