<?php

namespace Silk\Widgets;

use Closure;
use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Tree\Widget;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Windows\Primitives\ImageScaling;
use Surface\NutsAndBolts\Color;

/**
 * `image(name, file)` as TKImage: a picture scaled into its bounds by ImageScaling (fit, fill, center,
 * stretch), centred; fill and center clip to the bounds. It wants the image's own size, one pixel a unit.
 * Rounded corners and the tint's colour wait on the painter (see EnginePainter::image).
 */
class Image extends Widget
{
    /** Turns a file path into a framebuffer; null uses the container's image decoder. */
    public static ?Closure $decoder = null;

    private ?Framebuffer $framebuffer = null;

    private bool $decoded = false;

    private ImageScaling $scaling = ImageScaling::FIT;

    private float $radius = 0.0;

    private ?Color $tint = null;

    public function __construct(string $name, private ?string $file = null)
    {
        parent::__construct($name);
    }

    public function file(): ?string
    {
        return $this->file;
    }

    /** null clears the image. */
    public function setFile(?string $file): static
    {
        [$this->file, $this->framebuffer, $this->decoded] = [$file, null, false];

        return $this->invalidateLayout();
    }

    /** An image already in memory, in place of a file. */
    public function setFramebuffer(?Framebuffer $framebuffer): static
    {
        [$this->file, $this->framebuffer, $this->decoded] = [null, $framebuffer, true];

        return $this->invalidateLayout();
    }

    public function setScaling(ImageScaling $scaling): static
    {
        $this->scaling = $scaling;

        return $this->invalidatePaint();
    }

    public function setCornerRadius(float $radius): static
    {
        $this->radius = $radius;

        return $this->invalidatePaint();
    }

    public function setTint(?Color $tint): static
    {
        $this->tint = $tint;

        return $this->invalidatePaint();
    }

    /** The picture, decoded once from the file. */
    public function framebuffer(): ?Framebuffer
    {
        if (! $this->decoded) {
            $this->decoded = true;
            $this->framebuffer = $this->file === null ? null : self::decode($this->file);
        }

        return $this->framebuffer;
    }

    protected function measureContent(Size $available): Size
    {
        $fb = $this->framebuffer();

        return $fb === null ? new Size(0, 0) : new Size($fb->viewportWidth(), $fb->viewportHeight());
    }

    public function paint(Painter $p): void
    {
        $fb = $this->framebuffer();
        $b = $this->bounds();
        if ($fb === null || $b->w <= 0 || $b->h <= 0) {
            return;
        }
        [$w, $h] = [$fb->viewportWidth(), $fb->viewportHeight()];
        $k = match ($this->scaling) {
            ImageScaling::FIT => min($b->w / $w, $b->h / $h),
            ImageScaling::FILL => max($b->w / $w, $b->h / $h),
            default => 1.0,
        };
        $r = $this->scaling === ImageScaling::STRETCH
            ? $b
            : new Rect($b->x + ($b->w - $w * $k) / 2, $b->y + ($b->h - $h * $k) / 2, $w * $k, $h * $k);
        $clip = $r->x < $b->x || $r->y < $b->y || $r->w > $b->w || $r->h > $b->h;
        $clip && $p->pushClip($b);
        $p->image($fb, $r, $this->radius, $this->tint);
        $clip && $p->popClip();
    }

    private static function decode(string $file): ?Framebuffer
    {
        if (self::$decoder !== null) {
            return (self::$decoder)($file);
        }
        $bytes = @file_get_contents($file);
        if ($bytes === false || ! function_exists('app')) {
            return null;
        }

        return app('images')->decode($bytes);
    }
}
