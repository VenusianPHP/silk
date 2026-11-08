<?php

namespace Venusian\Silk\Tests\Support;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Painter\Painter;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\NutsAndBolts\Color;
use Silk\Contracts\Look\FontSpec;

/** A Painter that records every call as [method, args]. */
class SpyPainter implements Painter
{
    /** @var list<array{string, array<int, mixed>}> */
    public array $calls = [];

    public function __construct(
        private float $scale = 1.0,
        private ?Rect $viewport = null,
    ) {}

    private function record(string $method, array $args): static
    {
        $this->calls[] = [$method, $args];

        return $this;
    }

    /** @return list<string> the recorded method names, in order */
    public function methods(): array
    {
        return array_column($this->calls, 0);
    }

    public function rect(Rect $r, Color $fill, float|array $radii = 0.0, ?Color $border = null, float $borderWidth = 0.0): static
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function gradient(Rect $r, \Surface\NutsAndBolts\Gradient $paint, float|array $radii = 0.0): static
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function glass(Rect $r, float|array $radii, \Silk\Contracts\Painter\GlassLook $look): static
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function blur(Rect $r, float|array $radii, \Silk\Contracts\Painter\BlurLook $look): static
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function fill(\Surface\NutsAndBolts\Path $path, Color|\Surface\NutsAndBolts\Gradient $paint, \Surface\Contracts\Rasterize\FillRule $rule = \Surface\Contracts\Rasterize\FillRule::NON_ZERO): static
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function stroke(\Surface\NutsAndBolts\Path $path, Color|\Surface\NutsAndBolts\Gradient $paint, \Surface\Contracts\Drawing\StrokeStyle $style): static
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    /** Records the layer, then what $draw paints in it; 'endLayer' closes it. */
    public function layer(Rect $r, \Closure $draw, float $opacity = 1.0, float|array $radii = 0.0, ?\Surface\Contracts\Drawing\Effects\FilterChain $filter = null, ?\Surface\Contracts\Drawing\Effects\FilterChain $backdrop = null): static
    {
        $this->record(__FUNCTION__, [$r, $opacity, $radii, $filter, $backdrop]);
        $draw($this);

        return $this->record('endLayer', []);
    }

    public function shadow(Rect $r, float $radius, float $blur, Color $color, float $dx = 0.0, float $dy = 0.0): static
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function line(float $x1, float $y1, float $x2, float $y2, Color $color, float $thickness = 1.0): static
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function triangle(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3, Color $color): static
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function image(Framebuffer $fb, Rect $r, float $radius = 0.0, ?Color $tint = null): static
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function text(string $text, float $x, float $y, Color $color, FontSpec $style): static
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function sketch(Rect $r, \Closure $draw): static
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function pushClip(Rect $r): static
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function popClip(): static
    {
        return $this->record(__FUNCTION__, []);
    }

    public function pushOpacity(float $o): static
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function popOpacity(): static
    {
        return $this->record(__FUNCTION__, []);
    }

    public function pushTransform(float $scale, float $tx, float $ty, ?float $scaleY = null): static
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    public function popTransform(): static
    {
        return $this->record(__FUNCTION__, []);
    }

    public function scale(): float
    {
        return $this->scale;
    }

    public function viewport(): Rect
    {
        return $this->viewport ?? new Rect(0, 0, 800, 600);
    }
}
