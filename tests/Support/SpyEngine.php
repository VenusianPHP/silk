<?php

namespace Venusian\Silk\Tests\Support;

use Surface\Contracts\Fonts\Font;
use Surface\Contracts\Framebuffers\Filter;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Rasterize\FillRule;
use Surface\Drawing\RenderingEngine;
use Surface\Framebuffers\Native\NativeFullFramebuffer;
use Surface\NutsAndBolts\Color;

/** A RenderingEngine that draws nothing and records each public drawing call as [method, args]. */
class SpyEngine extends RenderingEngine
{
    /** @var list<array{string, array<int, mixed>}> */
    public array $calls = [];

    private Framebuffer $framebuffer;

    /** Over $framebuffer when given (as Velvet over a canvas's own), else over one of its own. */
    public function __construct(int $width = 400, int $height = 300, ?Framebuffer $framebuffer = null)
    {
        $this->framebuffer = $framebuffer ?? new NativeFullFramebuffer(FormatSpec::rgba8(), $width, $height);
    }

    public function name(): string
    {
        return 'spy';
    }

    public function framebuffer(): Framebuffer
    {
        return $this->framebuffer;
    }

    protected function execute(array $commands): void {}

    /** @return list<string> */
    public function methods(): array
    {
        return array_column($this->calls, 0);
    }

    /** @return list<array<int, mixed>> the args of every call to $method */
    public function argsOf(string $method): array
    {
        return array_values(array_map(fn ($c) => $c[1], array_filter($this->calls, fn ($c) => $c[0] === $method)));
    }

    public function clip(?Region $region): static
    {
        $this->calls[] = [__FUNCTION__, func_get_args()];

        return parent::clip($region);
    }


    public function fillRect(float $x, float $y, float $width, float $height, Color $color): static
    {
        $this->calls[] = [__FUNCTION__, func_get_args()];

        return parent::fillRect($x, $y, $width, $height, $color);
    }

    public function fillPath(array $contours, Color $color, FillRule $rule = FillRule::NON_ZERO): static
    {
        $this->calls[] = [__FUNCTION__, [$contours, $color, $rule]];

        return parent::fillPath($contours, $color, $rule);
    }

    public function fillTriangle(float $x0, float $y0, float $x1, float $y1, float $x2, float $y2, Color $color): static
    {
        $this->calls[] = [__FUNCTION__, func_get_args()];

        return parent::fillTriangle($x0, $y0, $x1, $y1, $x2, $y2, $color);
    }

    public function polyline(array $points, Color $color, float $stroke = 1.0, bool $closed = false): static
    {
        $this->calls[] = [__FUNCTION__, func_get_args()];

        return parent::polyline($points, $color, $stroke, $closed);
    }

    public function roundRect(float $x, float $y, float $width, float $height, array $radii, Color $fill, ?Color $border = null, float $borderWidth = 0.0): static
    {
        $this->calls[] = [__FUNCTION__, func_get_args()];

        return parent::roundRect($x, $y, $width, $height, $radii, $fill, $border, $borderWidth);
    }

    public function shadow(float $x, float $y, float $width, float $height, float $radius, float $blur, Color $color, float $dx = 0.0, float $dy = 0.0): static
    {
        $this->calls[] = [__FUNCTION__, func_get_args()];

        return parent::shadow($x, $y, $width, $height, $radius, $blur, $color, $dx, $dy);
    }

    public function fill(\Surface\NutsAndBolts\Path $path, Color|\Surface\NutsAndBolts\Gradient $paint, \Surface\Contracts\Rasterize\FillRule $rule = \Surface\Contracts\Rasterize\FillRule::NON_ZERO): static
    {
        $this->calls[] = [__FUNCTION__, func_get_args()];

        return parent::fill($path, $paint, $rule);
    }

    public function stroke(\Surface\NutsAndBolts\Path $path, Color|\Surface\NutsAndBolts\Gradient $paint, \Surface\Contracts\Drawing\StrokeStyle $style): static
    {
        $this->calls[] = [__FUNCTION__, func_get_args()];

        return parent::stroke($path, $paint, $style);
    }

    public function image(Framebuffer $source, float $x, float $y, ?float $width = null, ?float $height = null, float $opacity = 1.0, Filter $filter = Filter::NEAREST, ?array $radii = null, ?\Surface\NutsAndBolts\Color $tint = null, ?array $sourceRect = null): static
    {
        $this->calls[] = [__FUNCTION__, func_get_args()];

        return parent::image($source, $x, $y, $width, $height, $opacity, $filter, $radii, $tint, $sourceRect);
    }

    public function layer(float $x, float $y, float $width, float $height, callable $draw, float $opacity = 1.0, ?array $radii = null, ?\Surface\Contracts\Drawing\Effects\FilterChain $filter = null, ?\Surface\Contracts\Drawing\Effects\FilterChain $backdrop = null): static
    {
        $this->calls[] = [__FUNCTION__, [$x, $y, $width, $height, $opacity, $radii, $filter, $backdrop]];

        return parent::layer($x, $y, $width, $height, $draw, $opacity, $radii, $filter, $backdrop);
    }

    public function glass(float $x, float $y, float $width, float $height, ?array $radii, \Surface\Contracts\Drawing\Effects\Glass $glass, float $opacity = 1.0): static
    {
        $this->calls[] = [__FUNCTION__, func_get_args()];

        return parent::glass($x, $y, $width, $height, $radii, $glass, $opacity);
    }

    public function text(string $text, float $x, float $y, Color $color, Font $font): static
    {
        $this->calls[] = [__FUNCTION__, func_get_args()];

        return parent::text($text, $x, $y, $color, $font);
    }
}
