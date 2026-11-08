<?php

namespace Silk\Contracts\Painter;

use Silk\Contracts\Geometry\Rect;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\NutsAndBolts\Color;
use Surface\NutsAndBolts\Gradient;
use Surface\NutsAndBolts\Path;
use Surface\Contracts\Drawing\StrokeStyle;
use Surface\Contracts\Drawing\Effects\FilterChain;
use Surface\Contracts\Rasterize\FillRule;
use Silk\Contracts\Look\FontSpec;

/**
 * The seam between Silk widgets and a RenderingEngine. Widgets paint in logical units; the
 * painter scales, snaps rect edges, clamps strokes and radii, intersects clips and multiplies
 * opacity into colours.
 */
interface Painter
{
    /**
     * A filled rect with per-corner radii and an inside border.
     *
     * @param float|array{float, float, float, float} $radii one radius, or [tl, tr, br, bl]
     */
    public function rect(Rect $r, Color $fill, float|array $radii = 0.0, ?Color $border = null, float $borderWidth = 0.0): static;

    /**
     * $r, rounded by $radii (one radius, or [tl, tr, br, bl]), painted in a gradient given in the same units as the
     * rect: a Surface LinearGradient or RadialGradient, its stops faded by the opacity pushed.
     *
     * @param float|array{float, float, float, float} $radii
     */
    public function gradient(Rect $r, Gradient $paint, float|array $radii = 0.0): static;

    /** $path, in logical units, filled in a colour or a gradient given in the same units; faded by the opacity pushed. */
    public function fill(Path $path, Color|Gradient $paint, FillRule $rule = FillRule::NON_ZERO): static;

    /** $path stroked; $style's width and dash are in logical units. */
    public function stroke(Path $path, Color|Gradient $paint, StrokeStyle $style): static;

    /**
     * A group drawn apart and laid down in one piece (Surface's layer): $draw paints it with this painter, clipped to
     * $r rounded by $radii; it is laid down at $opacity (and the opacity pushed). $filter runs over the group, $backdrop
     * over what is under $r first. Filter chains work in device pixels: a length is scale() times the transform's.
     *
     * @param  \Closure(Painter): void  $draw
     * @param  float|array{float, float, float, float}  $radii
     */
    public function layer(Rect $r, \Closure $draw, float $opacity = 1.0, float|array $radii = 0.0, ?FilterChain $filter = null, ?FilterChain $backdrop = null): static;

    /** A soft shadow under the rounded rect $r, offset by $dx, $dy. */
    public function shadow(Rect $r, float $radius, float $blur, Color $color, float $dx = 0.0, float $dy = 0.0): static;

    /**
     * A pane of what lies behind $r, out of focus, inside $r rounded by $radii (one radius, or [tl, tr, br, bl]): Raven's
     * blur primitive (rvn_paint_blur, draw.c:545). It reads the pixels already painted, so what is painted after it lies on top.
     *
     * @param float|array{float, float, float, float} $radii
     */
    public function blur(Rect $r, float|array $radii, BlurLook $look): static;

    /**
     * A slab of glass over what lies behind $r, inside $r rounded by $radii: Raven's glass primitive (rvn_paint_glass,
     * draw.c:504). Its flat middle shows the backdrop, more or less frosted; its rim bends it like a lens, splits its colours
     * a little and catches the light. It reads the pixels already painted.
     *
     * @param float|array{float, float, float, float} $radii
     */
    public function glass(Rect $r, float|array $radii, GlassLook $look): static;

    /** A line with round caps; not snapped. */
    public function line(float $x1, float $y1, float $x2, float $y2, Color $color, float $thickness = 1.0): static;

    public function triangle(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3, Color $color): static;

    /** A framebuffer drawn into $r, rounded by $radius and multiplied by $tint. */
    public function image(Framebuffer $fb, Rect $r, float $radius = 0.0, ?Color $tint = null): static;

    /** Text in $style with its baseline origin at $x, $y. */
    public function text(string $text, float $x, float $y, Color $color, FontSpec $font): static;

    /** Intersects $r with the current clip. */
    /**
     * Hands the engine to $draw($engine, $deviceRect, $scale) for a rect, clipped to it, with the engine's
     * transform and clip put back afterwards: how a hand-drawn view sits in a Silk tree.
     */
    public function sketch(Rect $r, \Closure $draw): static;

    public function pushClip(Rect $r): static;

    public function popClip(): static;

    /** Multiplies $o into the current opacity. */
    public function pushOpacity(float $o): static;

    public function popOpacity(): static;

    /** Scales by $scale, then translates by $tx, $ty, for a painted subtree. */
    /**
     * What is painted next maps (x, y) to (x * scale + tx, y * scaleY + ty) within the current transform. $scaleY null
     * is $scale: a uniform scale. Apart (a stretch, Raven rvn_drawlist_transform), text stretches with the shapes.
     */
    public function pushTransform(float $scale, float $tx, float $ty, ?float $scaleY = null): static;

    public function popTransform(): static;

    /** Device pixels per logical unit: window scale x UI scale. */
    public function scale(): float;

    /** The paintable area in logical units. */
    public function viewport(): Rect;
}
