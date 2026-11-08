<?php

namespace Silk\Core\Layout;

use InvalidArgumentException;
use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Widget\Slot;
use Silk\Contracts\Widget\Widget as WidgetContract;
use Silk\Core\Tree\Container;

/**
 * `fixed(name)` as TKFixed; Raven's Canvas. Each child has anchors (fractions of the panel), a position, a
 * size and a pivot, per axis. Equal anchors: the child sits at anchor + position, at its size (its own when
 * the size is <= 0), shifted by -pivot * size. Different anchors: it stretches between them, position and
 * size being the near and far margins. at() is the TKFixed frame: anchors 0, position x/y, size w/h.
 * It asks for no space; children may lie outside it.
 */
class Anchored extends Container
{
    /** @var array{array{float, float, float, float}, array{float, float}, array{float, float}, array{float, float}}|null */
    private ?array $next = null;

    /** @var array<string, array{array{float, float, float, float}, array{float, float}, array{float, float}, array{float, float}}> */
    private array $places = [];

    /** The frame of the next child added, as TKFixed. */
    public function at(float $x, float $y, float $width, float $height): static
    {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException("A frame needs width and height >= 1, not {$width}x{$height}.");
        }
        $this->next = [[0.0, 0.0, 0.0, 0.0], [$x, $y], [$width, $height], [0.0, 0.0]];

        return $this;
    }

    /**
     * The anchored place of the next child added.
     *
     * @param  array{float, float, float, float}  $anchors  min x, min y, max x, max y as fractions
     * @param  array{float, float}  $pivot
     */
    public function anchor(array $anchors, float $x = 0.0, float $y = 0.0, float $width = 0.0, float $height = 0.0, array $pivot = [0.0, 0.0]): static
    {
        $this->next = [array_map('floatval', array_values($anchors)), [$x, $y], [$width, $height], array_map('floatval', array_values($pivot))];

        return $this;
    }

    /** Without at() or anchor(), a child sits at the top-left at its own size. */
    public function add(WidgetContract $child, ?Slot $slot = null): static
    {
        $this->places[$child->uuid()] = $this->next ?? [[0.0, 0.0, 0.0, 0.0], [0.0, 0.0], [0.0, 0.0], [0.0, 0.0]];
        $this->next = null;

        return parent::add($child, $slot);
    }

    public function move(WidgetContract $child, float $x, float $y): static
    {
        $this->places[$this->known($child)][1] = [$x, $y];

        return $this->invalidateLayout();
    }

    public function resize(WidgetContract $child, float $width, float $height): static
    {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException("A frame needs width and height >= 1, not {$width}x{$height}.");
        }
        $this->places[$this->known($child)][2] = [$width, $height];

        return $this->invalidateLayout();
    }

    /** The child's bounds from the last arrange. */
    public function frameOf(WidgetContract $child): Rect
    {
        $this->known($child);

        return $child->bounds();
    }

    protected function measureContent(Size $available): Size
    {
        foreach ($this->visibleChildren() as $child) {
            $child->measure($available);
        }

        return new Size(0, 0);
    }

    protected function arrangeContent(Rect $r): void
    {
        foreach ($this->visibleChildren() as $child) {
            [$anchors, $position, $size, $pivot] = $this->places[$child->uuid()];
            $desired = $child->measure(new Size($r->w, $r->h));
            [$x, $w] = self::axis($r->w, $anchors[0], $anchors[2], $position[0], $size[0], $desired->w, $pivot[0]);
            [$y, $h] = self::axis($r->h, $anchors[1], $anchors[3], $position[1], $size[1], $desired->h, $pivot[1]);
            $child->arrange(new Rect($r->x + $x, $r->y + $y, $w, $h));
        }
    }

    /** @return array{float, float} position and length on one axis */
    private static function axis(float $panel, float $min, float $max, float $position, float $size, float $desired, float $pivot): array
    {
        if ($max > $min) {
            $near = $panel * $min + $position;

            return [$near, max(0.0, $panel * $max - $size - $near)];
        }
        $length = $size > 0 ? $size : $desired;

        return [$panel * $min + $position - $pivot * $length, $length];
    }

    private function known(WidgetContract $child): string
    {
        return isset($this->places[$child->uuid()]) && $child->parent() === $this
            ? $child->uuid()
            : throw new InvalidArgumentException("'{$child->name()}' is not in '{$this->name()}'.");
    }
}
