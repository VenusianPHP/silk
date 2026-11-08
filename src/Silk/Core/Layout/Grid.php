<?php

namespace Silk\Core\Layout;

use InvalidArgumentException;
use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Widget\Slot;
use Silk\Contracts\Widget\Widget as WidgetContract;
use Silk\Core\Tree\Container;

/**
 * `grid(name, spacing, padding)` as TKGrid; Raven's Grid. at() gives the cell (and spans) of the next child
 * added. Tracks are sized to their content (auto), stretched by a fill coefficient (star), or fixed.
 * Single-track cells size the tracks first; a spanning cell then grows the last track it covers.
 */
class Grid extends Container
{
    /** @var array{int, int, int, int}|null the cell for the next add */
    private ?array $next = null;

    /** @var array<string, array{int, int, int, int}> row, column, rowSpan, columnSpan by child uuid */
    private array $cells = [];

    /** @var array<int, float> */
    private array $columnFill = [];

    /** @var array<int, float> */
    private array $rowFill = [];

    /** @var array<int, float> */
    private array $columnFixed = [];

    /** @var array<int, float> */
    private array $rowFixed = [];

    public function __construct(string $name, private float $spacing = 0, private float $padding = 0)
    {
        parent::__construct($name);
    }

    /** The cell of the next child added. */
    public function at(int $row, int $column, int $rowSpan = 1, int $columnSpan = 1): static
    {
        if ($row < 0 || $column < 0 || $rowSpan < 1 || $columnSpan < 1) {
            throw new InvalidArgumentException("A grid cell needs row and column >= 0 and spans >= 1, not ({$row}, {$column}, {$rowSpan}, {$columnSpan}).");
        }
        $this->next = [$row, $column, $rowSpan, $columnSpan];

        return $this;
    }

    /** Without at(), a child goes to the next column of row 0. */
    public function add(WidgetContract $child, ?Slot $slot = null): static
    {
        $this->cells[$child->uuid()] = $this->next ?? [0, count($this->children()), 1, 1];
        $this->next = null;

        return parent::add($child, $slot);
    }

    public function spacing(): float
    {
        return $this->spacing;
    }

    public function setSpacing(float $spacing): static
    {
        $this->spacing = $spacing;

        return $this->invalidateLayout();
    }

    /** A star track: the space left over is shared by fill coefficient. 0 makes it auto again. */
    public function setColumnFill(int $column, float $fill): static
    {
        $this->columnFill[$column] = max(0.0, $fill);

        return $this->invalidateLayout();
    }

    public function setRowFill(int $row, float $fill): static
    {
        $this->rowFill[$row] = max(0.0, $fill);

        return $this->invalidateLayout();
    }

    /** A fixed track; null makes it auto again. */
    public function setColumnWidth(int $column, ?float $width): static
    {
        if ($width === null) {
            unset($this->columnFixed[$column]);
        } else {
            $this->columnFixed[$column] = max(0.0, $width);
        }

        return $this->invalidateLayout();
    }

    public function setRowHeight(int $row, ?float $height): static
    {
        if ($height === null) {
            unset($this->rowFixed[$row]);
        } else {
            $this->rowFixed[$row] = max(0.0, $height);
        }

        return $this->invalidateLayout();
    }

    /** @return array{int, int, int, int} row, column, rowSpan, columnSpan */
    public function cellOf(WidgetContract $child): array
    {
        return $this->cells[$child->uuid()];
    }

    protected function measureContent(Size $available): Size
    {
        [$columns, $rows] = $this->tracks($this->inner($available));

        return new Size(self::total($columns, $this->spacing) + 2 * $this->padding, self::total($rows, $this->spacing) + 2 * $this->padding);
    }

    protected function arrangeContent(Rect $r): void
    {
        $inner = $this->inner(new Size($r->w, $r->h));
        [$columns, $rows] = $this->tracks($inner);
        $columns = $this->stretch($columns, $this->columnFill, $inner->w);
        $rows = $this->stretch($rows, $this->rowFill, $inner->h);
        foreach ($this->visibleChildren() as $child) {
            [$row, $column, $rowSpan, $columnSpan] = $this->cellOf($child);
            $child->arrange(new Rect(
                $r->x + $this->padding + self::offset($columns, $column, $this->spacing),
                $r->y + $this->padding + self::offset($rows, $row, $this->spacing),
                self::span($columns, $column, $columnSpan, $this->spacing),
                self::span($rows, $row, $rowSpan, $this->spacing),
            ));
        }
    }

    /** @return array{list<float>, list<float>} natural column widths and row heights */
    private function tracks(Size $inner): array
    {
        $columns = $rows = [];
        $kids = $this->visibleChildren();
        foreach ($kids as $child) {
            [$row, $column, $rowSpan, $columnSpan] = $this->cellOf($child);
            for ($i = 0; $i < $column + $columnSpan; $i++) {
                $columns[$i] ??= 0.0;
            }
            for ($i = 0; $i < $row + $rowSpan; $i++) {
                $rows[$i] ??= 0.0;
            }
        }
        $sizes = [];
        foreach ($kids as $child) {
            $sizes[$child->uuid()] = $child->measure($inner);
            [$row, $column, $rowSpan, $columnSpan] = $this->cellOf($child);
            if ($columnSpan === 1) {
                $columns[$column] = max($columns[$column], $sizes[$child->uuid()]->w);
            }
            if ($rowSpan === 1) {
                $rows[$row] = max($rows[$row], $sizes[$child->uuid()]->h);
            }
        }
        foreach ($kids as $child) {
            [$row, $column, $rowSpan, $columnSpan] = $this->cellOf($child);
            if ($columnSpan > 1) {
                $columns = self::fitSpan($columns, $column, $columnSpan, $this->spacing, $sizes[$child->uuid()]->w);
            }
            if ($rowSpan > 1) {
                $rows = self::fitSpan($rows, $row, $rowSpan, $this->spacing, $sizes[$child->uuid()]->h);
            }
        }
        foreach ($this->columnFixed as $i => $w) {
            isset($columns[$i]) && $columns[$i] = $w;
        }
        foreach ($this->rowFixed as $i => $h) {
            isset($rows[$i]) && $rows[$i] = $h;
        }

        return [$columns, $rows];
    }

    /** @param list<float> $sizes @param array<int, float> $fill @return list<float> */
    private function stretch(array $sizes, array $fill, float $available): array
    {
        $extra = $available - self::total($sizes, $this->spacing);
        $total = 0.0;
        foreach ($fill as $i => $f) {
            $total += isset($sizes[$i]) ? $f : 0.0;
        }
        if ($extra <= 0 || $total <= 0) {
            return $sizes;
        }
        foreach ($fill as $i => $f) {
            if (isset($sizes[$i])) {
                $sizes[$i] += $extra * $f / $total;
            }
        }

        return $sizes;
    }

    private function inner(Size $s): Size
    {
        return new Size(max(0.0, $s->w - 2 * $this->padding), max(0.0, $s->h - 2 * $this->padding));
    }

    /** @param list<float> $sizes @return list<float> the missing space goes to the last track of the span */
    private static function fitSpan(array $sizes, int $first, int $span, float $spacing, float $needed): array
    {
        $total = self::span($sizes, $first, $span, $spacing);
        if ($needed > $total) {
            $sizes[$first + $span - 1] += $needed - $total;
        }

        return $sizes;
    }

    /** @param list<float> $sizes */
    private static function total(array $sizes, float $spacing): float
    {
        return array_sum($sizes) + $spacing * max(0, count($sizes) - 1);
    }

    /** @param list<float> $sizes */
    private static function offset(array $sizes, int $index, float $spacing): float
    {
        return array_sum(array_slice($sizes, 0, $index)) + $spacing * $index;
    }

    /** @param list<float> $sizes */
    private static function span(array $sizes, int $first, int $span, float $spacing): float
    {
        return array_sum(array_slice($sizes, $first, $span)) + $spacing * ($span - 1);
    }
}
