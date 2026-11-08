<?php

namespace Silk\Widgets;

use Closure;
use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\FontSpec;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\State;
use Silk\Contracts\Mail\RowActivated;
use Silk\Contracts\Mail\TilesSelected;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Drag\DragPayload;
use Silk\Core\Tree\Widget;
use Silk\Widgets\Concerns\MeasuresText;
use Silk\Widgets\Rows\RowScroll;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\NutsAndBolts\Color;

/**
 * `tileView(name)`: Raven's grid of tiles, an icon over a name, that scrolls vertically (tile_view.c). The tiles
 * come from the app (setItems: a count and a callback that gives a TileItem) and only the visible ones are asked
 * for and drawn. Several selected (Ctrl toggles, Shift takes the range from the anchor, Ctrl+A all, a rubber band
 * from the empty space, which Ctrl or Shift adds to); a press on a selected tile keeps the others until release;
 * a double click on the current tile or Enter activates (RowActivated); each selection change posts
 * TilesSelected. With a drag type, a press that moves more than 5 units drags the selection. The selection is one
 * byte a tile. Sizes default from the TileView part (Raven 112 x 112 tiles, 64 icon, spacing 8).
 */
class TileView extends Widget
{
    use MeasuresText;

    public const float DRAG_START = 5.0;

    private int $count = 0;

    private ?Closure $item = null;

    private ?float $tileW = null;

    private ?float $tileH = null;

    private ?float $iconSize = null;

    private ?float $spacing = null;

    private TileMode $mode = TileMode::Icons;

    /** One byte a tile: "\1" selected. */
    private string $selected = '';

    private int $current = -1;

    private int $anchor = -1;

    private int $hot = -1;

    private bool $hovered = false;

    private bool $hasFocus = false;

    private RowScroll $bar;

    /** A press that may become a click, a drag or a band. */
    private bool $pressPending = false;

    private int $pressIndex = -1;

    /** @var array{float, float} */
    private array $pressAt = [0.0, 0.0];

    private bool $pressReduce = false;

    /** The band, in content coordinates, and the selection it adds to. @var array{float, float, float, float}|null */
    private ?array $band = null;

    private ?string $bandBase = null;

    private ?string $dragType = null;

    private ?Closure $dragData = null;

    private int $dropIndex = -1;

    private ?string $emptyText = null;

    /** @var array{float, float} */
    private array $pointer = [-1.0, -1.0];

    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->focusable = true;
        $this->bar = new RowScroll;
    }

    /** The tiles: $item(int $index): TileItem, asked only for the tiles drawn. The selection does not carry over. */
    public function setItems(int $count, Closure $item): static
    {
        [$this->count, $this->item] = [max(0, $count), $item];
        $this->selected = str_repeat("\0", $this->count);
        $this->current = $this->anchor = $this->hot = $this->pressIndex = $this->dropIndex = -1;
        [$this->pressPending, $this->band, $this->bandBase] = [false, null, null];

        return $this->invalidateLayout();
    }

    /** The tiles changed but not their number: draw them again. */
    public function refresh(): static
    {
        return $this->invalidatePaint();
    }

    public function count(): int
    {
        return $this->count;
    }

    public function setTileSize(float $w, float $h, float $icon): static
    {
        [$this->tileW, $this->tileH, $this->iconSize] = [max(16.0, $w), max(16.0, $h), max(4.0, $icon)];

        return $this->invalidateLayout();
    }

    public function setSpacing(float $spacing): static
    {
        $this->spacing = max(0.0, $spacing);

        return $this->invalidateLayout();
    }

    public function setMode(TileMode $mode): static
    {
        $this->mode = $mode;

        return $this->invalidateLayout();
    }

    public function mode(): TileMode
    {
        return $this->mode;
    }

    public function setEmptyText(?string $text): static
    {
        $this->emptyText = $text;

        return $this->invalidatePaint();
    }

    public function setDragType(?string $type): static
    {
        $this->dragType = $type === '' ? null : $type;

        return $this;
    }

    /** The app's own drag data (paths...) instead of the indices: $data(): string. */
    public function setDragData(?Closure $data): static
    {
        $this->dragData = $data;

        return $this;
    }

    public function setDropHighlight(int $index): static
    {
        $this->dropIndex = $index;

        return $this->invalidatePaint();
    }

    /** @return list<int> */
    public function selected(): array
    {
        $out = [];
        for ($i = strpos($this->selected, "\1"); $i !== false; $i = strpos($this->selected, "\1", $i + 1)) {
            $out[] = $i;
        }

        return $out;
    }

    public function selectionCount(): int
    {
        return substr_count($this->selected, "\1");
    }

    public function isSelected(int $i): bool
    {
        return ($this->selected[$i] ?? "\0") === "\1";
    }

    public function current(): int
    {
        return $this->current;
    }

    /** Posts nothing. */
    public function setSelected(int $i, bool $on): static
    {
        if ($i < 0 || $i >= $this->count) {
            return $this;
        }
        $this->selected[$i] = $on ? "\1" : "\0";
        $on ? $this->current = $i : ($this->current === $i && $this->current = -1);

        return $this->invalidatePaint();
    }

    /** Only $i (-1: none), revealed. Posts nothing. */
    public function selectOnly(int $i): static
    {
        $this->only($i, false);

        return $this;
    }

    public function clearSelection(): static
    {
        return $this->selectOnly(-1);
    }

    public function scrollTo(int $i): static
    {
        $this->reveal($i);

        return $this;
    }

    public function scroll(): RowScroll
    {
        return $this->bar;
    }

    public function springs(): array
    {
        return $this->bar->springs();
    }

    public function settleMotion(): void
    {
        parent::settleMotion();
        $this->bar->shown->atRest() || $this->invalidatePaint();
    }

    /** @return array{float, float, float, float} tile width, tile height, icon size, spacing: given, else the TileView part's */
    private function sizes(): array
    {
        $e = $this->look()->part('TileView', 'Body', State::Normal)->extra;

        return [$this->tileW ?? (float) ($e['tileWidth'] ?? 112.0), $this->tileH ?? (float) ($e['tileHeight'] ?? 112.0),
            $this->iconSize ?? (float) ($e['iconSize'] ?? 64.0), $this->spacing ?? (float) ($e['spacing'] ?? 8.0)];
    }

    /** Whole tiles across, at least one; one in Rows mode (tile_view.c:96-103). */
    public function columns(): int
    {
        if ($this->mode === TileMode::Rows) {
            return 1;
        }
        [$tw, , , $sp] = $this->sizes();

        return max(1, (int) floor(($this->bounds()->w - $sp - $this->look()->metric(Metric::ScrollbarSize)) / ($tw + $sp)));
    }

    /** A tile's width: the tile's, or in Rows mode the view less the spacing and the bar (tile_view.c:86-94). */
    private function tileWidth(): float
    {
        [$tw, , , $sp] = $this->sizes();

        return $this->mode === TileMode::Rows ? max(16.0, $this->bounds()->w - $sp * 2 - $this->look()->metric(Metric::ScrollbarSize)) : $tw;
    }

    private function pitch(): float
    {
        [, $th, , $sp] = $this->sizes();

        return $th + $sp;
    }

    /** Tile $i, in layout coordinates at scroll $offset (tile_view.c:128-136). */
    public function tileRect(int $i, ?float $offset = null): Rect
    {
        [, $th, , $sp] = $this->sizes();
        $b = $this->bounds();
        $cols = $this->columns();
        $w = $this->tileWidth();

        return new Rect($b->x + $sp + ($w + $sp) * ($i % $cols), $b->y + $sp + $this->pitch() * intdiv($i, $cols) - ($offset ?? $this->bar->offset), $w, $th);
    }

    /** The tile at layout $x, $y; the gaps between tiles are empty space (tile_view.c:138-153). */
    public function tileAt(float $x, float $y): int
    {
        [, $th, , $sp] = $this->sizes();
        $b = $this->bounds();
        $cx = $x - $b->x - $sp;
        $cy = $y - $b->y + $this->bar->offset - $sp;
        if ($cx < 0 || $cy < 0) {
            return -1;
        }
        $w = $this->tileWidth();
        $col = (int) floor($cx / ($w + $sp));
        $row = (int) floor($cy / $this->pitch());
        if ($col >= $this->columns() || $cx - $col * ($w + $sp) > $w || $cy - $row * $this->pitch() > $th) {
            return -1;
        }
        $i = $row * $this->columns() + $col;

        return $i < $this->count ? $i : -1;
    }

    public function track(): Rect
    {
        $b = $this->bounds();
        $s = $this->look()->metric(Metric::ScrollbarSize);

        return new Rect($b->x + $b->w - $s - 1, $b->y + 1, $s, max(0.0, $b->h - 2));
    }

    /** Two tiles and three spacings each way (tile_view.c:105-108). */
    protected function measureContent(Size $available): Size
    {
        [$tw, $th, , $sp] = $this->sizes();

        return new Size($tw * 2 + $sp * 3, $th * 2 + $sp * 3);
    }

    protected function arrangeContent(Rect $r): void
    {
        $this->syncBar();
    }

    private function syncBar(): void
    {
        [, , , $sp] = $this->sizes();
        $rows = (int) ceil($this->count / $this->columns());
        $this->bar->content = $this->pitch() * $rows + $sp;
        $this->bar->viewport = max(0.0, $this->bounds()->h);
        $this->bar->clamp();
    }

    private function reveal(int $i): void
    {
        if ($i < 0) {
            return;
        }
        [, , , $sp] = $this->sizes();
        $this->syncBar();
        $r = $this->tileRect($i, 0.0);
        $y = $r->y - $this->bounds()->y;
        if ($y - $sp < $this->bar->offset) {
            $this->bar->set($y - $sp);
        } elseif ($y + $r->h + $sp > $this->bar->offset + $this->bar->viewport) {
            $this->bar->set($y + $r->h + $sp - $this->bar->viewport);
        }
        $this->invalidatePaint();
    }

    public function handle(SilkEvent $e): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }
        if (in_array($e->type, [EventType::MouseDown, EventType::MouseMove, EventType::MouseUp], true)) {
            $this->pointer = [$e->x, $e->y];
            if ($this->band === null && $this->bar->event($e, $this->track(), true)) {
                $this->invalidatePaint();

                return true;
            }
        }
        $b = $this->bounds();
        switch ($e->type) {
            case EventType::Enter:
                $this->hovered = true;

                return false;
            case EventType::MouseMove:
                $at = $this->tileAt($e->x, $e->y);
                if ($at !== $this->hot) {
                    $this->hot = $at;
                    $this->invalidatePaint();
                }
                if ($this->band !== null) {
                    $this->band[2] = max($b->x, min($b->x + $b->w, $e->x));
                    // past the top or the bottom: the grid scrolls
                    if ($e->y < $b->y) {
                        $this->bar->set($this->bar->offset + ($e->y - $b->y) * 0.3);
                    } elseif ($e->y > $b->y + $b->h) {
                        $this->bar->set($this->bar->offset + ($e->y - $b->y - $b->h) * 0.3);
                    }
                    $this->band[3] = max($b->y, min($b->y + $b->h, $e->y)) - $b->y + $this->bar->offset;
                    $this->bandUpdate();

                    return true;
                }

                return $this->maybeDrag($e);
            case EventType::Leave:
                [$this->hot, $this->hovered] = [-1, false];
                $this->invalidatePaint();

                return false;
            case EventType::MouseDown:
                return $e->button === MouseButton::LEFT && $this->press($e);
            case EventType::MouseUp:
                if ($e->button !== MouseButton::LEFT) {
                    return false;
                }
                if ($this->band !== null) {
                    [$this->band, $this->bandBase] = [null, null];
                    $this->invalidatePaint();
                } elseif ($this->pressPending && $this->pressReduce && $this->pressIndex >= 0) {
                    $this->only($this->pressIndex, true);
                }
                [$this->pressPending, $this->pressReduce] = [false, false];

                return true;
            case EventType::Wheel:
                // with Ctrl the wheel is left to the app (zoom)
                if ($e->modifiers->ctrl) {
                    return false;
                }
                if (! $this->bar->wheel($e->wheelY * $this->pitch() * 0.75)) {
                    $this->invalidatePaint();

                    return false;
                }
                $this->hot = $this->tileAt($e->x, $e->y);
                $this->invalidatePaint();

                return true;
            case EventType::FocusGained:
            case EventType::FocusLost:
                $this->hasFocus = $e->type === EventType::FocusGained;
                $this->invalidatePaint();

                return false;
            case EventType::KeyDown:
                return $this->key($e);
            default:
                return false;
        }
    }

    public function paint(Painter $p): void
    {
        $look = $this->look();
        $b = $this->bounds();
        [, , $iconSize, $sp] = $this->sizes();
        $offset = $this->bar->drawn();
        $cols = $this->columns();
        $pitch = $this->pitch();
        $first = max(0, (int) floor(($offset - $sp) / $pitch) * $cols);
        $last = min($this->count, ((int) floor(($offset + $b->h) / $pitch) + 2) * $cols);
        $radius = $look->metric(Metric::CornerRadius);
        $style = $this->textStyle();
        $text = $this->color($this->isEnabled() ? Role::Text : Role::TextDisabled, $look);
        $selection = $this->color(Role::Selection, $look);
        $accent = $this->color(Role::Accent, $look);
        $p->pushClip($b);
        if ($this->count === 0 && ($this->emptyText ?? '') !== '') {
            $band = new Rect($b->x, $b->y, $b->w, $style->size * 3);
            $p->text($this->emptyText, $b->x + ($b->w - $this->textSize($this->emptyText)->w) / 2, $this->baselineIn($band, $style),
                $this->color(Role::TextDisabled, $look), $style);
        }
        for ($i = $first; $i < $last; $i++) {
            $item = ($this->item)($i);
            $r = $this->tileRect($i, $offset);
            if ($this->isSelected($i)) {
                $p->rect($r, self::fade($selection, $this->hasFocus ? 0.85 : 0.5), $radius, $i === $this->current ? $accent : null, $i === $this->current ? 2.0 : 0.0);
            } elseif ($i === $this->dropIndex) {
                $p->rect($r, self::fade($accent, 0.15), $radius, $accent, 1.5);
            } elseif ($i === $this->hot && $this->hovered && $this->isEnabled()) {
                $p->rect($r, $this->color(Role::RowHovered, $look), $radius);
            }
            $ink = $item->textColor ?? $text;
            $this->mode === TileMode::Rows ? $this->paintRowItem($p, $item, $r, $iconSize, $style, $ink, $text, $i) : $this->paintTile($p, $item, $r, $iconSize, $style, $ink, $text, $i);
        }
        if ($this->band !== null) {
            [$x0, $y0, $x1, $y1] = $this->band;
            $band = new Rect(min($x0, $x1), $b->y + min($y0, $y1) - $offset, abs($x1 - $x0), abs($y1 - $y0));
            $p->rect($band, self::fade($accent, 0.18), 2.0, self::fade($accent, 0.9), 1.0);
        }
        $p->popClip();
        $this->bar->paint($p, $look, $this->track(), true, $this->hovered, $this->track()->contains(...$this->pointer));
    }

    /** Rows mode: the icon at the left, the name and a dimmer detail, a badge at the right (tile_view.c:183-209). */
    private function paintRowItem(Painter $p, TileItem $item, Rect $r, float $iconSize, FontSpec $style, Color $ink, Color $text, int $i): void
    {
        $icon = min($iconSize, $r->h - 6);
        $item->icon && $icon >= 4 && $p->image($item->icon, new Rect(floor($r->x + 6), floor($r->y + ($r->h - $icon) / 2), $icon, $icon), $item->iconRadius);
        $x = $r->x + 6 + ($item->icon ? $icon + 10 : 0.0);
        $small = new FontSpec($style->family, $style->size * 0.85, $style->weight, $style->italic);
        $detail = ($item->detail ?? '') !== '';
        $textH = $style->size + 4 + ($detail ? $style->size * 0.85 + 6 : 0.0);
        $y = floor($r->y + ($r->h - $textH) / 2);
        $p->pushClip(new Rect($x, $r->y, $r->x + $r->w - $x - 4, $r->h));
        $p->text($item->text, $x, $this->baselineIn(new Rect($x, $y, 0, $style->size + 4), $style), $ink, $style);
        $detail && $p->text($item->detail, $x, $this->baselineIn(new Rect($x, $y + $style->size + 6, 0, $style->size + 2), $small),
            $this->isSelected($i) ? $text : $this->color(Role::TextDisabled, $this->look()), $small);
        if (($item->badge ?? '') !== '') {
            $p->text($item->badge, $r->x + $r->w - 8 - $this->textSize($item->badge, $small)->w, $this->baselineIn(new Rect($x, $y, 0, $style->size + 4), $small), self::fade($text, 0.7), $small);
        }
        $p->popClip();
    }

    /** Tiles mode: the icon (a small hop when hovered), a badge at the top right, the name centred (or from the left when too long) and the detail (tile_view.c:211-241). */
    private function paintTile(Painter $p, TileItem $item, Rect $r, float $iconSize, FontSpec $style, Color $ink, Color $text, int $i): void
    {
        $icon = max(4.0, min($iconSize, min($r->w - 8, $r->h - $style->size - 14)));
        if ($item->icon) {
            $hop = $i === $this->hot && $this->band === null ? -2.0 : 0.0;
            $p->image($item->icon, new Rect(floor($r->x + ($r->w - $icon) / 2), floor($r->y + 5 + $hop), $icon, $icon), $item->iconRadius);
        }
        if (($item->badge ?? '') !== '') {
            $badge = new FontSpec($style->family, $style->size * 0.8, $style->weight, $style->italic);
            $p->text($item->badge, $r->x + $r->w - 4 - $this->textSize($item->badge, $badge)->w, $this->baselineIn(new Rect($r->x, $r->y + 2, 0, $style->size), $badge), self::fade($text, 0.7), $badge);
        }
        $labelY = $r->y + 5 + $icon + 2;
        $p->pushClip(new Rect($r->x + 3, $labelY, $r->w - 6, $r->y + $r->h - $labelY));
        $w = $this->textSize($item->text, $style)->w;
        // a name too long starts at the left (its beginning is what tells the files apart)
        $x = $w > $r->w - 8 ? $r->x + 4 : $r->x + 4 + ($r->w - 8 - $w) / 2;
        $p->text($item->text, $x, $this->baselineIn(new Rect($r->x, $labelY, 0, $style->size + 6), $style), $ink, $style);
        if (($item->detail ?? '') !== '') {
            $small = new FontSpec($style->family, $style->size * 0.85, $style->weight, $style->italic);
            $dw = $this->textSize($item->detail, $small)->w;
            $p->text($item->detail, $r->x + 4 + max(0.0, ($r->w - 8 - $dw) / 2), $this->baselineIn(new Rect($r->x, $labelY + $style->size + 4, 0, $style->size + 4), $small),
                $this->color(Role::TextDisabled, $this->look()), $small);
        }
        $p->popClip();
    }

    /** tile_view.c:443-456 */
    private function press(SilkEvent $e): bool
    {
        $b = $this->bounds();
        $i = $this->tileAt($e->x, $e->y);
        [$this->pressPending, $this->pressIndex, $this->pressReduce, $this->pressAt] = [true, $i, false, [$e->x, $e->y]];
        $m = $e->modifiers;
        if ($i < 0) {
            // the empty space: a rubber band (Ctrl / Shift keep what is selected)
            $keep = $m->ctrl || $m->meta || $m->shift;
            $this->bandBase = $keep ? $this->selected : null;
            $keep || $this->only(-1, true);
            $y = $e->y - $b->y + $this->bar->offset;
            $this->band = [$e->x, $y, $e->x, $y];

            return true;
        }
        if ($e->clicks === 2 && $i === $this->current) {
            $this->pressPending = false;
            $this->activate();

            return true;
        }
        if ($m->shift) {
            [$from, $to] = [$this->anchor >= 0 ? $this->anchor : $i, $i];
            $m->ctrl || $m->meta || $this->selected = str_repeat("\0", $this->count);
            for ($k = min($from, $to); $k <= max($from, $to); $k++) {
                $this->selected[$k] = "\1";
            }
            $this->current = $i;
            $this->notify();
        } elseif ($m->ctrl || $m->meta) {
            $this->selected[$i] = $this->isSelected($i) ? "\0" : "\1";
            $this->anchor = $i;
            $this->current = $this->isSelected($i) ? $i : (int) (strrpos($this->selected, "\1") === false ? -1 : strrpos($this->selected, "\1"));
            $this->notify();
        } elseif ($this->isSelected($i)) {
            // a press on a selected tile keeps the others (a drag takes them all)
            $this->pressReduce = true;
            if ($this->current !== $i) {
                $this->current = $i;
                $this->notify();
            }
        } else {
            $this->only($i, true);
        }

        return true;
    }

    /** The tiles the band touches join its base (tile_view.c:345-357); the current is the last inside. */
    private function bandUpdate(): void
    {
        [$x0, $y0, $x1, $y1] = $this->band;
        $b = $this->bounds();
        [$x0, $x1, $y0, $y1] = [min($x0, $x1), max($x0, $x1), $b->y + min($y0, $y1), $b->y + max($y0, $y1)];
        $sel = $this->bandBase ?? str_repeat("\0", $this->count);
        $last = -1;
        [, , , $sp] = $this->sizes();
        $pitch = $this->pitch();
        $cols = $this->columns();
        $from = max(0, (int) floor(($y0 - $b->y - $sp) / $pitch) * $cols);
        $to = min($this->count, ((int) floor(($y1 - $b->y) / $pitch) + 1) * $cols);
        for ($i = $from; $i < $to; $i++) {
            $r = $this->tileRect($i, 0.0);
            if ($r->x <= $x1 && $r->x + $r->w >= $x0 && $r->y <= $y1 && $r->y + $r->h >= $y0) {
                $sel[$i] = "\1";
                $last = $i;
            }
        }
        $this->selected = $sel;
        $last >= 0 && $this->current = $last;
        $this->notify();
    }

    private function maybeDrag(SilkEvent $e): bool
    {
        if (! $this->pressPending || $this->pressIndex < 0 || $this->dragType === null || $this->tree() === null) {
            return false;
        }
        if (abs($e->x - $this->pressAt[0]) <= self::DRAG_START && abs($e->y - $this->pressAt[1]) <= self::DRAG_START) {
            return true;
        }
        [$this->pressPending, $this->pressReduce] = [false, false];
        $ids = $this->selected();
        $data = $this->dragData !== null ? (string) ($this->dragData)() : implode(' ', $ids);
        $text = ($this->item)($this->pressIndex)->text;
        $label = count($ids) > 1 ? sprintf('%s (+%d)', $text, count($ids) - 1) : $text;
        [$dx, $dy] = $this->tree()->shiftOf($this);
        $this->tree()->beginDrag($this, new DragPayload($label, $this->dragType, $data), $e->x + $dx, $e->y + $dy);

        return true;
    }

    /** tile_view.c:495-525 */
    private function key(SilkEvent $e): bool
    {
        if ($this->count === 0) {
            return false;
        }
        $cols = $this->columns();
        $next = $this->current;
        switch ($e->key) {
            case Key::LEFT:
                $next = $next < 0 ? 0 : $next - 1;
                break;
            case Key::RIGHT:
                $next++;
                break;
            case Key::UP:
                $next = $next < 0 ? 0 : $next - $cols;
                break;
            case Key::DOWN:
                $next = $next < 0 ? 0 : $next + $cols;
                break;
            case Key::HOME:
                $next = 0;
                break;
            case Key::END:
                $next = $this->count - 1;
                break;
            case Key::ENTER:
            case Key::NUMPAD_ENTER:
                $this->activate();

                return true;
            case Key::A:
                if (! ($e->modifiers->ctrl || $e->modifiers->meta)) {
                    return false;
                }
                $this->selected = str_repeat("\1", $this->count);
                $this->current < 0 && $this->current = 0;
                $this->notify();

                return true;
            default:
                return false;
        }
        $this->only(max(0, min($this->count - 1, $next)), true);

        return true;
    }

    private function only(int $i, bool $notify): void
    {
        $this->selected = str_repeat("\0", $this->count);
        $i = $i >= 0 && $i < $this->count ? $i : -1;
        $i >= 0 && $this->selected[$i] = "\1";
        [$this->current, $this->anchor] = [$i, $i];
        $this->reveal($i);
        $notify ? $this->notify() : $this->invalidatePaint();
    }

    private function notify(): void
    {
        $this->invalidatePaint();
        $this->post(new TilesSelected($this->window(), $this->path(), $this->uuid(), $this->selected(), $this->current));
    }

    private function activate(): void
    {
        $this->current >= 0 && $this->post(new RowActivated($this->window(), $this->path(), $this->uuid(), $this->current));
    }

    private static function fade(Color $c, float $a): Color
    {
        return new Color($c->red, $c->green, $c->blue, $c->alpha * $a);
    }
}
