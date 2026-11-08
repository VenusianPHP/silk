<?php

namespace Silk\Painter;

use LogicException;
use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Painter\BlurLook;
use Silk\Contracts\Painter\GlassLook;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Framebuffers\Native\NativeFullFramebuffer;
use Silk\Contracts\Painter\Painter;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Fonts\GFXFont;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Drawing\Effects\Glass;
use Surface\Contracts\Framebuffers\Region;
use Surface\NutsAndBolts\Affine;
use Surface\NutsAndBolts\Color;
use Surface\NutsAndBolts\Gradient;
use Surface\NutsAndBolts\LinearGradient;
use Surface\NutsAndBolts\RadialGradient;
use Surface\NutsAndBolts\Path;
use Surface\Contracts\Drawing\StrokeStyle;
use Surface\Contracts\Drawing\Effects\FilterChain;
use Surface\Contracts\Rasterize\FillRule;
use Silk\Contracts\Look\FontSpec;
use Silk\Text\TextMeasurer;

/**
 * The Painter on a RenderingEngine. Widgets paint in logical units; this maps through the pushed
 * transforms, scales to device pixels, snaps rect edges, clamps strokes and radii, keeps the clip
 * stack and the opacity, culls, and lowers through EngineLowering (the engine's UI ops). Call it inside engine->frame().
 */
class EnginePainter implements Painter
{
    public const float CULL_MARGIN = 48.0;

    private ClipStack $clips;

    /** @var list<float> */
    private array $opacity = [1.0];

    /** @var list<array{float, float, float, bool}> logical scale, tx, ty; and whether the push set an engine transform */
    private array $transforms = [[1.0, 0.0, 0.0, false]];

    public function __construct(
        private readonly RenderingEngine $engine,
        private readonly float $scale,
        private readonly ?GFXFont $font = null,
        private readonly ?TextMeasurer $measurer = null,
    ) {
        $this->clips = new ClipStack(new Rect(0, 0, $engine->width(), $engine->height()));
    }

    public function rect(Rect $r, Color $fill, float|array $radii = 0.0, ?Color $border = null, float $borderWidth = 0.0): static
    {
        $d = $this->device($r);
        if ($d === null) {
            return $this;
        }
        [$s] = $this->top();
        $k = $this->scale * $s;
        $radii = EngineLowering::clamp(array_map(fn ($v) => $v * $k, is_array($radii) ? $radii : array_fill(0, 4, $radii)), $d->w, $d->h);
        EngineLowering::rect($this->engine, $d->x, $d->y, $d->w, $d->h, $radii, $this->fade($fill),
            $border === null ? null : $this->fade($border), Snap::stroke($borderWidth * $s, $this->scale));

        return $this;
    }

    public function gradient(Rect $r, Gradient $paint, float|array $radii = 0.0): static
    {
        $d = $this->device($r);
        if ($d === null) {
            return $this;
        }
        [$s] = $this->top();
        $k = $this->scale * $s;
        $radii = EngineLowering::clamp(array_map(fn ($v) => $v * $k, is_array($radii) ? $radii : array_fill(0, 4, $radii)), $d->w, $d->h);
        $this->engine->fill(RoundedPath::of($d, $radii), $this->devicePaint($paint));

        return $this;
    }

    public function fill(Path $path, Color|Gradient $paint, FillRule $rule = FillRule::NON_ZERO): static
    {
        $this->clips->isEmpty() || $this->engine->fill($path->transformed($this->matrix()), $this->devicePaint($paint), $rule);

        return $this;
    }

    public function stroke(Path $path, Color|Gradient $paint, StrokeStyle $style): static
    {
        if ($this->clips->isEmpty()) {
            return $this;
        }
        $k = $this->scale * $this->top()[0];
        $device = new StrokeStyle($style->width * $k, $style->cap, $style->join, $style->miterLimit, array_map(fn ($d) => $d * $k, $style->dash), $style->dashOffset * $k);
        $this->engine->stroke($path->transformed($this->matrix()), $this->devicePaint($paint), $device);

        return $this;
    }

    public function layer(Rect $r, \Closure $draw, float $opacity = 1.0, float|array $radii = 0.0, ?FilterChain $filter = null, ?FilterChain $backdrop = null): static
    {
        if ($r->w <= 0 || $r->h <= 0 || $this->clips->isEmpty()) {
            return $this;
        }
        $k = $this->scale * $this->top()[0];
        [$x0, $y0] = $this->point($r->x, $r->y);
        // the group is laid down at the opacity; inside it nothing is faded twice
        $saved = $this->opacity;
        $alpha = end($saved) * min(1.0, max(0.0, $opacity));
        $this->opacity = [1.0];
        try {
            $this->engine->layer($x0, $y0, $r->w * $k, $r->h * $k, fn () => $draw($this), $alpha,
                array_map(fn ($v) => $v * $k, is_array($radii) ? $radii : array_fill(0, 4, $radii)), $filter, $backdrop);
        } finally {
            $this->opacity = $saved;
        }

        return $this;
    }

    /** Logical units to device pixels, through the pushed transform. */
    private function matrix(): Affine
    {
        [$s, $tx, $ty] = $this->top();

        return Affine::translation($tx * $this->scale, $ty * $this->scale)->multiply(Affine::scaling($s * $this->scale, $s * $this->scale));
    }

    /** A colour faded by the opacity; a gradient's points in device pixels, its stops faded. */
    private function devicePaint(Color|Gradient $paint): Color|Gradient
    {
        if ($paint instanceof Color) {
            return $this->fade($paint);
        }
        $k = $this->scale * $this->top()[0];
        $stops = array_map(fn (array $stop): array => [$stop[0], $this->fade($stop[1])], $paint->stops);

        return match (true) {
            $paint instanceof LinearGradient => new LinearGradient(...$this->point($paint->x0, $paint->y0), ...$this->point($paint->x1, $paint->y1), stops: $stops, spread: $paint->spread),
            $paint instanceof RadialGradient => new RadialGradient(...$this->point($paint->cx, $paint->cy), radius: $paint->radius * $k, stops: $stops, spread: $paint->spread),
            default => throw new \InvalidArgumentException('A painter paints a LinearGradient or a RadialGradient.'),
        };
    }

    /**
     * The pane is a Surface glass pane (RenderingEngine::glass): the backdrop under the rounded box blurred by Raven's
     * radius, saturated, grained, tinted and lit. The shadow goes on after, outside the box only (four strips around it), because
     * Raven takes the backdrop before the shadow (draw.c:556) and the layer reads what is painted. The hairline is an
     * ordinary rect outline over it.
     */
    public function blur(Rect $r, float|array $radii, BlurLook $look): static
    {
        $d = $this->device($r);
        if ($d === null) {
            return $this;
        }
        [$s] = $this->top();
        $k = $this->scale * $s;
        $device = EngineLowering::clamp(array_map(fn ($v) => $v * $k, is_array($radii) ? $radii : array_fill(0, 4, $radii)), $d->w, $d->h);
        $opacity = min(1.0, max(0.0, end($this->opacity)));
        if ($opacity > 0.0) {
            $this->engine->glass($d->x, $d->y, $d->w, $d->h, $device, new Glass(
                blur: min(96.0, max(0.0, $look->radius)) * $k, tint: $look->tint, saturation: max(0.0, $look->saturation), light: $look->light, grain: max(0.0, $look->grain),
            ), $opacity);
        }
        if ($look->shadow > 0 && $look->shadowColor !== null) {
            $this->shadowAround($r, is_array($radii) ? $radii[0] : $radii, $look->shadow, $look->shadowColor);
        }
        if ($look->border !== null && $look->borderWidth > 0) {
            $this->rect($r, new Color(0, 0, 0, 0), $radii, $look->border, $look->borderWidth);
        }

        return $this;
    }

    /**
     * The slab is a Surface glass pane (RenderingEngine::glass): the backdrop under it frosted by Raven's wide blur, bent at
     * the rim by the refraction across the bevel, saturated, tinted, given its glow and lit at the rim, at the opacity.
     */
    public function glass(Rect $r, float|array $radii, GlassLook $look): static
    {
        $d = $this->device($r);
        if ($d === null) {
            return $this;
        }
        [$s] = $this->top();
        $k = $this->scale * $s;
        $device = EngineLowering::clamp(array_map(fn ($v) => $v * $k, is_array($radii) ? $radii : array_fill(0, 4, $radii)), $d->w, $d->h);
        $opacity = min(1.0, max(0.0, end($this->opacity)));
        $half = min($d->w, $d->h) / 2;
        $bevel = min(($look->bevel > 0.0 ? $look->bevel : max(3.0, min(14.0, min($r->w, $r->h) * 0.42))) * $k, $half * 0.95);
        $refraction = max(0.0, $look->refraction) * $k;

        if ($opacity > 0.0) {
            // Raven's slab: its wide frost blur (22 device px), bent at the rim by the refraction across the bevel, lit by the rim colour
            $this->engine->glass($d->x, $d->y, $d->w, $d->h, $device, new Glass(
                blur: 22.0, tint: $look->tint, saturation: max(0.0, $look->saturation), light: $look->glow, rim: $look->rim,
                bevel: $bevel, refraction: $refraction, frost: max(0.0, min(1.0, $look->frost)),
            ), $opacity);
        }
        if ($look->shadow > 0 && $look->shadowColor !== null) {
            $this->shadowAround($r, is_array($radii) ? $radii[0] : $radii, $look->shadow, $look->shadowColor);
        }

        return $this;
    }

    /** The shadow of $r outside $r only: four clipped strips, so a pane's backdrop-read area is not darkened by it. */
    private function shadowAround(Rect $r, float $radius, float $blur, Color $color): void
    {
        $reach = $blur * 2 + 2;
        $y = $blur / 4;
        $strips = [
            new Rect($r->x - $reach, $r->y - $reach, $r->w + 2 * $reach, $reach),
            new Rect($r->x - $reach, $r->y + $r->h, $r->w + 2 * $reach, $reach + $y),
            new Rect($r->x - $reach, $r->y, $reach, $r->h),
            new Rect($r->x + $r->w, $r->y, $reach, $r->h),
        ];
        foreach ($strips as $strip) {
            $this->pushClip($strip);
            $this->shadow($r, $radius, $blur, $color);
            $this->popClip();
        }
    }

    public function shadow(Rect $r, float $radius, float $blur, Color $color, float $dx = 0.0, float $dy = 0.0): static
    {
        // the blur reaches outside the rect; cull on the grown rect
        $grown = new Rect($r->x + $dx - $blur, $r->y + $dy - $blur, $r->w + 2 * $blur, $r->h + 2 * $blur);
        if ($this->culled($grown)) {
            return $this;
        }
        [$s, $tx, $ty] = $this->top();
        $k = $this->scale * $s;
        EngineLowering::shadow($this->engine, (($r->x + $dx) * $s + $tx) * $this->scale, (($r->y + $dy) * $s + $ty) * $this->scale,
            $r->w * $k, $r->h * $k, $radius * $k, $blur * $k, $this->fade($color));

        return $this;
    }

    public function line(float $x1, float $y1, float $x2, float $y2, Color $color, float $thickness = 1.0): static
    {
        $t = $thickness / 2;
        if ($this->culled(new Rect(min($x1, $x2) - $t, min($y1, $y2) - $t, abs($x2 - $x1) + 2 * $t, abs($y2 - $y1) + 2 * $t))) {
            return $this;
        }
        [$s] = $this->top();
        [$ax, $ay] = $this->point($x1, $y1);
        [$bx, $by] = $this->point($x2, $y2);
        // Raven rvn_paint_line (draw.c:612): max(1, thickness x scale), not rounded; the edge is antialiased
        EngineLowering::line($this->engine, $ax, $ay, $bx, $by, $this->fade($color), max(1.0, $thickness * $s * $this->scale));

        return $this;
    }

    public function triangle(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3, Color $color): static
    {
        $xs = [$x1, $x2, $x3];
        $ys = [$y1, $y2, $y3];
        if ($this->culled(new Rect(min($xs), min($ys), max($xs) - min($xs), max($ys) - min($ys)))) {
            return $this;
        }
        $this->engine->fillTriangle(...$this->point($x1, $y1), ...$this->point($x2, $y2), ...$this->point($x3, $y3), color: $this->fade($color));

        return $this;
    }

    /**
     * The image cut to a rounded rect of $radius (logical units, all four corners) and multiplied by $tint, alpha
     * included, as RenderingEngine::image() does both.
     */
    public function image(Framebuffer $fb, Rect $r, float $radius = 0.0, ?Color $tint = null): static
    {
        $d = $this->device($r);
        if ($d === null) {
            return $this;
        }
        [$s] = $this->top();
        $radii = $radius > 0.0 ? array_fill(0, 4, $radius * $this->scale * $s) : null;
        $this->engine->image($fb, $d->x, $d->y, $d->w, $d->h, min(1.0, max(0.0, end($this->opacity))), radii: $radii, tint: $tint);

        return $this;
    }

    /**
     * Shaped at device size (style size x scale, so 26 px at 2x, not 13 px scaled up) and drawn as TrueType text on
     * the baseline rounded to whole device pixels. The engine places text by its top and adds the ascent back, so
     * it lands on the same baseline. Without a TrueType font (no font exts) the GFXFont draws at its own size.
     */
    public function text(string $text, float $x, float $y, Color $color, FontSpec $font): static
    {
        if ($text === '' || $this->clips->isEmpty()) {
            return $this;
        }
        [$s] = $this->top();
        [$dx, $dy] = $this->point($x, $y);
        $true = $this->measurer?->font($font);
        if ($true !== null) {
            $sized = $true->at($font->size * $this->scale * $s);
            $run = $sized->shape($sized->size, $text);
            $x = round($dx);
            $top = round($dy) - $run->ascent;
            // culled like the shapes: a run whose box misses the clip draws nothing, and the engine never sees it
            $box = new Rect($x, $top, $run->advance, $run->ascent + $run->descent);
            if ($box->w > 0 && $box->intersect($this->clips->current()) === null) {
                return $this;
            }
            $this->engine->text($text, $x, $top, $this->fade($color), $sized);
        } elseif ($this->font !== null) {
            $this->engine->text($text, round($dx), round($dy), $this->fade($color), $this->font);
        }

        return $this;
    }

    public function sketch(Rect $r, \Closure $draw): static
    {
        $d = $this->device($r);
        $clip = $d === null ? null : $this->clips->current();
        $visible = $clip === null ? null : (new Region((int) $d->x, (int) $d->y, (int) $d->w, (int) $d->h))
            ->intersect(new Region((int) $clip->x, (int) $clip->y, (int) $clip->w, (int) $clip->h));
        if ($visible === null || $visible->isEmpty()) {
            return $this;
        }
        [$s] = $this->top();
        // push/pop puts back the engine's transform and clip, whatever $draw did to them
        $this->engine->push();
        try {
            $this->engine->clip($visible);
            $draw($this->engine, $d, $this->scale * $s);
        } finally {
            $this->engine->pop();
        }

        return $this;
    }

    public function pushClip(Rect $r): static
    {
        $this->clips->push($this->device($r, cull: false) ?? new Rect(0, 0, 0, 0));

        return $this->engineClip();
    }

    public function popClip(): static
    {
        $this->clips->pop();

        return $this->engineClip();
    }

    /** Silk intersects; the engine is handed the result, the surface once the stack is back to its base. */
    private function engineClip(): static
    {
        $c = $this->clips->current();
        $this->engine->clip(new Region((int) $c->x, (int) $c->y, (int) $c->w, (int) $c->h));

        return $this;
    }

    public function pushOpacity(float $o): static
    {
        $this->opacity[] = end($this->opacity) * min(1.0, max(0.0, $o));

        return $this;
    }

    public function popOpacity(): static
    {
        if (count($this->opacity) === 1) {
            throw new LogicException('popOpacity() without a pushOpacity().');
        }
        array_pop($this->opacity);

        return $this;
    }

    public function pushTransform(float $scale, float $tx, float $ty, ?float $scaleY = null): static
    {
        [$s, $x, $y] = $this->top();
        if ($scaleY === null || $scaleY == $scale) {
            $this->transforms[] = [$s * $scale, $x + $tx * $s, $y + $ty * $s, false];

            return $this;
        }
        // a stretch: the engine's affine takes the translation and the two scales, in device pixels, so text stretches
        // with the shapes; inside it the painter maps as if untranslated at the same scale (Raven rvn_drawlist_transform)
        $this->engine->push();
        $this->engine->transform(Affine::translation(($tx * $s + $x) * $this->scale, ($ty * $s + $y) * $this->scale)->multiply(Affine::scaling($scale, $scaleY)));
        $this->transforms[] = [$s, 0.0, 0.0, true];

        return $this;
    }

    public function popTransform(): static
    {
        if (count($this->transforms) === 1) {
            throw new LogicException('popTransform() without a pushTransform().');
        }
        [, , , $engine] = array_pop($this->transforms);
        $engine && $this->engine->pop() && $this->engineClip();

        return $this;
    }

    public function scale(): float
    {
        return $this->scale;
    }

    public function viewport(): Rect
    {
        return new Rect(0, 0, $this->engine->width() / $this->scale, $this->engine->height() / $this->scale);
    }

    /** @return array{float, float, float} */
    private function top(): array
    {
        return $this->transforms[array_key_last($this->transforms)];
    }

    /** A logical point through the transform, in device pixels, unsnapped. @return array{float, float} */
    private function point(float $x, float $y): array
    {
        [$s, $tx, $ty] = $this->top();

        return [($x * $s + $tx) * $this->scale, ($y * $s + $ty) * $this->scale];
    }

    /** The logical rect through the transform, snapped to device pixels; null when empty, culled or clipped away. */
    private function device(Rect $r, bool $cull = true): ?Rect
    {
        if ($r->w <= 0 || $r->h <= 0 || ($cull && ($this->culled($r) || $this->clips->isEmpty()))) {
            return null;
        }
        [$s, $tx, $ty] = $this->top();
        $x0 = Snap::edge($r->x * $s + $tx, $this->scale);
        $y0 = Snap::edge($r->y * $s + $ty, $this->scale);
        $x1 = Snap::edge(($r->x + $r->w) * $s + $tx, $this->scale);
        $y1 = Snap::edge(($r->y + $r->h) * $s + $ty, $this->scale);

        return $x1 > $x0 && $y1 > $y0 ? new Rect($x0, $y0, $x1 - $x0, $y1 - $y0) : null;
    }

    /** True when the transformed rect lies more than CULL_MARGIN units outside the viewport. */
    private function culled(Rect $r): bool
    {
        if ($this->clips->isEmpty()) {
            return true;
        }
        [$s, $tx, $ty] = $this->top();
        $v = $this->viewport();
        $m = self::CULL_MARGIN;
        $x = $r->x * $s + $tx;
        $y = $r->y * $s + $ty;

        return $x + $r->w * $s < -$m || $y + $r->h * $s < -$m || $x > $v->w + $m || $y > $v->h + $m;
    }

    private function fade(Color $c): Color
    {
        $o = end($this->opacity);

        return $o >= 1.0 ? $c : new Color($c->red, $c->green, $c->blue, $c->alpha * $o);
    }
}
