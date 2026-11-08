<?php

namespace Silk\Widgets;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\FontSpec;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Mail\BlockEditorChanged;
use Silk\Contracts\Mail\BlockEditorClicked;
use Silk\Contracts\Mail\BlockEditorContext;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Silk\Core\Tree\Widget;
use Silk\Widgets\Blocks\Block;
use Silk\Widgets\Blocks\BlockKind;
use Silk\Widgets\Blocks\BlockLayout;
use Silk\Widgets\Blocks\BlockModel;
use Silk\Widgets\Blocks\BlockPlace;
use Silk\Widgets\Blocks\BlockShape;
use Silk\Widgets\Concerns\MeasuresText;
use Surface\Contracts\Drawing\StrokeStyle;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\NutsAndBolts\Color;
use Surface\NutsAndBolts\Path;

/**
 * `new BlockEditor(name)`: programs made of blocks that snap into each other, in the style of Scratch (Raven's block editor).
 * A palette on the left holds one block of each kind, by category; the scripts sit on a canvas that pans (drag the background
 * with the middle button or the right one, or Alt + left) and zooms (Ctrl + wheel). A kind has a label of words and slots
 * (`%n` number, `%s` text, `%b` condition, `%r` any value, `%m` a choice in a menu; `{text}` after a slot is its default; `|`
 * between the sections of a C block). Six shapes: statement, hat, cap, C, reporter, boolean.
 *
 * The user drags a block with what is under it; the place it would go opens and shows its shadow, and a slot that would take a
 * value lights up; dropped on the palette it is deleted. A click selects (Ctrl or Shift adds), a drag on the background draws a
 * selection rectangle, dragging a selected block moves the selection, Delete removes it, a click on a value slot types in it (a
 * menu slot opens its choices), a right click opens the context menu. The scripts live in a BlockModel (`model()`), which has
 * the rules; every change the user makes posts BlockEditorChanged, a click on a script posts BlockEditorClicked (the
 * application runs it and makes the running blocks glow with setBlockGlow), a right click BlockEditorContext.
 */
class BlockEditor extends Widget
{
    use MeasuresText;

    public const float ZOOM_MIN = 0.4;

    public const float ZOOM_MAX = 2.0;

    /** How far (units) the pointer must move after a press to start a drag. */
    public const float SLOP = 4.0;

    private const string NONE = 'none';

    private const string PRESS = 'press';

    private const string PRESS_SLOT = 'press-slot';

    private const string PRESS_PALETTE = 'press-palette';

    private const string CARRY = 'carry';

    private const string GROUP = 'group';

    private const string MARQUEE = 'marquee';

    private const string PAN = 'pan';

    private const string PRESS_RIGHT = 'press-right';

    private BlockModel $model;

    private ?BlockLayout $lay = null;

    private ?string $layFont = null;

    private bool $paletteVisible = true;

    private bool $builtInMenu = true;

    /** The canvas point at the top left of the canvas area, and the zoom. */
    private float $panX = 0.0;

    private float $panY = 0.0;

    private float $zoom = 1.0;

    private float $paletteScroll = 0.0;

    /** @var array<int, array{Spring, Spring}> where each block is shown (it glides to where the layout puts it) */
    private array $shown = [];

    private string $drag = self::NONE;

    private float $pressX = 0.0;

    private float $pressY = 0.0;

    private float $pointerX = 0.0;

    private float $pointerY = 0.0;

    private int $pressBlock = 0;

    private int $pressSlot = -1;

    private ?string $pressKind = null;

    private float $grabX = 0.0;

    private float $grabY = 0.0;

    private float $panFromX = 0.0;

    private float $panFromY = 0.0;

    private int $carried = 0;

    /** @var array{target: int, place: BlockPlace, index: int, top: int} */
    private array $dropAt = ['target' => 0, 'place' => BlockPlace::Next, 'index' => 0, 'top' => 0];

    /** @var array<int, array{float, float}> the tops a group move holds, with where they were */
    private array $group = [];

    /** @var array{float, float, float, float} the selection rectangle, canvas units */
    private array $marquee = [0.0, 0.0, 0.0, 0.0];

    /** @var array<int, bool> what was selected before the rectangle started */
    private array $selectedBefore = [];

    private bool $hasFocus = false;

    /** The slot being typed in: block, slot, and the text so far. */
    private int $editBlock = 0;

    private int $editSlot = -1;

    private string $editText = '';

    /** The text was selected when the edit opened: the first thing typed replaces it. */
    private bool $editFresh = false;

    /** @var array{kind: string, block: int, slot: int, x: float, y: float, rows: list<array{string, string}>}|null the open list of choices or menu */
    private ?array $list = null;

    private float $glowTime = 0.0;

    private bool $ticking = false;

    private bool $painted = false;

    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->focusable = true;
        $this->model = new BlockModel;
    }

    // ---------------------------------------------------------------- the scripts

    /** The scripts and the rules (kinds, attach, save, load...). Change them through the editor's methods to have it repaint. */
    public function model(): BlockModel
    {
        return $this->model;
    }

    /** A kind of block, and its block in the palette. @return int its index, -1 on an error */
    public function addKind(string $name, string $label, BlockShape $shape, string $category = ''): int
    {
        $i = $this->model->addKind($name, $label, $shape, $category);
        $this->invalidateLayout();

        return $i;
    }

    public function setCategoryColor(string $category, ?Color $color): static
    {
        $this->model->setCategoryColor($category, $color);

        return $this->invalidatePaint();
    }

    public function setPaletteVisible(bool $visible): static
    {
        $this->paletteVisible = $visible;

        return $this->invalidatePaint();
    }

    public function setSlotOptions(string $kind, int $slot, array $options): static
    {
        $this->model->setSlotOptions($kind, $slot, $options);

        return $this->invalidatePaint();
    }

    public function setKindVisible(string $kind, bool $visible): static
    {
        $this->model->setKindVisible($kind, $visible);

        return $this->invalidatePaint();
    }

    public function setKindLabel(string $kind, string $label): bool
    {
        $done = $this->model->setKindLabel($kind, $label);
        $this->invalidatePaint();

        return $done;
    }

    /** A new block, the top of a script of its own at x, y (canvas units). @return int 0 when the kind is unknown */
    public function addBlock(string $kind, float $x = 0.0, float $y = 0.0): int
    {
        $id = $this->model->addBlock($kind, $x, $y);
        $this->invalidatePaint();

        return $id;
    }

    public function removeBlock(int $id): static
    {
        $this->model->removeBlock($id);

        return $this->invalidatePaint();
    }

    public function clear(): static
    {
        $this->model->clear();
        $this->shown = [];

        return $this->invalidatePaint();
    }

    public function attach(int $block, int $target, BlockPlace $place, int $index = 0): bool
    {
        $done = $this->model->attach($block, $target, $place, $index);
        $this->invalidatePaint();

        return $done;
    }

    public function detach(int $block, float $x, float $y): static
    {
        $this->model->detach($block, $x, $y);

        return $this->invalidatePaint();
    }

    public function setValue(int $block, int $slot, string $value): static
    {
        $this->model->setValue($block, $slot, $value);

        return $this->invalidatePaint();
    }

    /** The block is running: a halo breathes around it. */
    public function setBlockGlow(int $block, bool $glow = true): static
    {
        $this->model->setGlow($block, $glow);

        return $this->invalidatePaint();
    }

    /** False: the user cannot delete the block (the entry of a function). Not saved. */
    public function setBlockDeletable(int $block, bool $deletable = true): static
    {
        $this->model->setLocked($block, ! $deletable);

        return $this;
    }

    public function save(): string
    {
        return $this->model->save();
    }

    public function load(string $text): bool
    {
        $done = $this->model->load($text);
        $this->shown = [];
        $this->invalidatePaint();

        return $done;
    }

    /** @return list<int> */
    public function selected(): array
    {
        return $this->model->selected();
    }

    public function select(int $block, bool $add = false): static
    {
        $this->model->select($block, $add);

        return $this->invalidatePaint();
    }

    /** Whether a right click opens the editor's own menu (Delete, Duplicate, Clean up, Select all); the mail is posted either way. */
    public function setBuiltInMenu(bool $on): static
    {
        $this->builtInMenu = $on;

        return $this;
    }

    /** @return array{float, float, float} the canvas point at the top left, and the zoom */
    public function view(): array
    {
        return [$this->panX, $this->panY, $this->zoom];
    }

    public function setView(float $x, float $y, float $zoom): static
    {
        [$this->panX, $this->panY, $this->zoom] = [$x, $y, max(self::ZOOM_MIN, min(self::ZOOM_MAX, $zoom))];

        return $this->invalidatePaint();
    }

    public function isCarrying(): bool
    {
        return $this->carried !== 0;
    }

    /** Where every block is (canvas units), as last laid out. */
    public function blockLayout(): BlockLayout
    {
        return $this->layoutNow();
    }

    protected function measureContent(Size $available): Size
    {
        return new Size(max(320.0, min($available->w, 900.0)), max(240.0, min($available->h, 560.0)));
    }

    public function springs(): array
    {
        $springs = parent::springs();
        foreach ($this->shown as [$sx, $sy]) {
            array_push($springs, $sx, $sy);
        }

        return $springs;
    }

    // ---------------------------------------------------------------- geometry

    private function font(): FontSpec
    {
        return $this->textStyle();
    }

    private function layoutNow(): BlockLayout
    {
        $font = $this->font();
        $key = $font->family.'|'.$font->size.'|'.$font->weight;
        if ($this->lay === null || $this->layFont !== $key) {
            $this->lay = new BlockLayout($this->model, fn (string $t) => $this->textSize($t, $this->font())->w, $font->size);
            $this->layFont = $key;
        }
        $this->lay->layout();

        return $this->lay;
    }

    /** The left of the canvas area, in the widget. */
    private function left(): float
    {
        return $this->paletteVisible ? BlockLayout::PALETTE_W : 0.0;
    }

    private function inPalette(float $x): bool
    {
        return $this->paletteVisible && $x < BlockLayout::PALETTE_W;
    }

    /** @return array{float, float} a point of the widget as a canvas point */
    private function toCanvas(float $x, float $y): array
    {
        return [$this->panX + ($x - $this->left()) / $this->zoom, $this->panY + $y / $this->zoom];
    }

    /** @return array{float, float} where a block is shown, canvas units */
    private function shownAt(Block $b): array
    {
        return isset($this->shown[$b->id]) ? [$this->shown[$b->id][0]->value(), $this->shown[$b->id][1]->value()] : [$b->lx, $b->ly];
    }

    /** Springs follow the layout: a new block starts where it is; the stack in the hand does not lag. */
    private function followLayout(BlockLayout $lay): void
    {
        $alive = [];
        foreach ($lay->drawn as $id) {
            if (($b = $this->model->block($id)) === null) {
                continue;
            }
            $alive[$id] = true;
            if (! isset($this->shown[$id])) {
                $this->shown[$id] = [(new Spring($b->lx, SpringPreset::Snappy))->snap($b->lx), (new Spring($b->ly, SpringPreset::Snappy))->snap($b->ly)];
            } elseif ($this->carried !== 0 && $this->model->inside($id, $this->carried)) {
                $this->shown[$id][0]->snap($b->lx);
                $this->shown[$id][1]->snap($b->ly);
            } else {
                $this->shown[$id][0]->target($b->lx);
                $this->shown[$id][1]->target($b->ly);
            }
        }
        $this->shown = array_intersect_key($this->shown, $alive);
    }

    private function changed(int $block): void
    {
        $this->invalidatePaint();
        $this->post(new BlockEditorChanged($this->window(), $this->path(), $this->uuid(), $block));
    }

    // ---------------------------------------------------------------- input

    public function handle(SilkEvent $e): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }
        $b = $this->bounds();
        [$x, $y] = [$e->x - $b->x, $e->y - $b->y];
        switch ($e->type) {
            case EventType::FocusGained:
            case EventType::FocusLost:
                $this->hasFocus = $e->type === EventType::FocusGained;
                $e->type === EventType::FocusLost && $this->finishEdit();
                $this->invalidatePaint();

                return false;
            case EventType::MouseDown:
                return $this->down($e, $x, $y);
            case EventType::MouseMove:
                return $this->move($e, $x, $y);
            case EventType::MouseUp:
                return $this->up($e, $x, $y);
            case EventType::Wheel:
                return $this->wheel($e, $x, $y);
            case EventType::Leave:
                return false;
            case EventType::Text:
                return $this->typed($e->text ?? '');
            case EventType::KeyDown:
                return $this->key($e);
            default:
                return false;
        }
    }

    private function down(SilkEvent $e, float $x, float $y): bool
    {
        [$this->pressX, $this->pressY, $this->pointerX, $this->pointerY] = [$x, $y, $x, $y];
        if ($this->list !== null) {
            $this->clickList($x, $y);

            return true;
        }
        $lay = $this->layoutNow();
        if ($e->button === MouseButton::MIDDLE || ($e->button === MouseButton::LEFT && $e->modifiers->alt)) {
            $this->finishEdit();
            $this->startPan();

            return true;
        }
        if ($e->button === MouseButton::RIGHT) {
            if ($this->inPalette($x)) {
                return true;
            }
            $this->finishEdit();
            $this->drag = self::PRESS_RIGHT;
            [$this->panFromX, $this->panFromY] = [$this->panX, $this->panY];

            return true;
        }
        if ($e->button !== MouseButton::LEFT) {
            return false;
        }
        $this->finishEdit();
        if ($this->inPalette($x)) {
            $this->pressKind = $lay->paletteAt($x, $y + $this->paletteScroll);
            if ($this->pressKind !== null) {
                $this->drag = self::PRESS_PALETTE;
            }

            return true;
        }
        [$cx, $cy] = $this->toCanvas($x, $y);
        [$id, $slot] = $lay->blockAt($cx, $cy, $this->shownAt(...));
        if ($id === 0) {
            $additive = $e->modifiers->ctrl || $e->modifiers->shift;
            $additive || $this->model->clearSelection();
            $this->selectedBefore = array_fill_keys($this->model->selected(), true);
            $this->marquee = [$cx, $cy, $cx, $cy];
            $this->drag = self::MARQUEE;
            $this->invalidatePaint();

            return true;
        }
        [$this->pressBlock, $this->pressSlot] = [$id, $slot];
        $this->drag = $slot >= 0 ? self::PRESS_SLOT : self::PRESS;

        return true;
    }

    private function startPan(): void
    {
        $this->drag = self::PAN;
        [$this->panFromX, $this->panFromY] = [$this->panX, $this->panY];
    }

    private function moved(): bool
    {
        return hypot($this->pointerX - $this->pressX, $this->pointerY - $this->pressY) > self::SLOP;
    }

    private function move(SilkEvent $e, float $x, float $y): bool
    {
        [$this->pointerX, $this->pointerY] = [$x, $y];
        switch ($this->drag) {
            case self::PRESS:
            case self::PRESS_SLOT:
                if ($this->moved()) {
                    $this->beginCarry($this->pressBlock);
                    $this->carryTo();
                }

                return true;
            case self::PRESS_PALETTE:
                if ($this->moved()) {
                    $this->fromPalette();
                    $this->carryTo();
                }

                return true;
            case self::CARRY:
                $this->carryTo();

                return true;
            case self::GROUP:
                $this->moveGroup();

                return true;
            case self::MARQUEE:
                [$cx, $cy] = $this->toCanvas($x, $y);
                [$this->marquee[2], $this->marquee[3]] = [$cx, $cy];
                $this->selectInMarquee();

                return true;
            case self::PAN:
            case self::PRESS_RIGHT:
                if ($this->drag === self::PAN || $this->moved()) {
                    $this->drag = self::PAN;
                    $this->panX = $this->panFromX - ($x - $this->pressX) / $this->zoom;
                    $this->panY = $this->panFromY - ($y - $this->pressY) / $this->zoom;
                    $this->invalidatePaint();
                }

                return true;
            default:
                return false;
        }
    }

    private function up(SilkEvent $e, float $x, float $y): bool
    {
        [$this->pointerX, $this->pointerY] = [$x, $y];
        $drag = $this->drag;
        $this->drag = self::NONE;
        switch ($drag) {
            case self::PRESS:
                $this->clickBlock($this->pressBlock, $e->modifiers->ctrl || $e->modifiers->shift);

                return true;
            case self::PRESS_SLOT:
                $this->openSlot($this->pressBlock, $this->pressSlot);

                return true;
            case self::PRESS_PALETTE:
                return true;
            case self::CARRY:
                $this->endCarry();

                return true;
            case self::GROUP:
                $this->group = [];
                $this->changed(0);

                return true;
            case self::MARQUEE:
                $this->invalidatePaint();

                return true;
            case self::PRESS_RIGHT:
                $this->contextAt($x, $y);

                return true;
            default:
                return $drag !== self::NONE;
        }
    }

    private function wheel(SilkEvent $e, float $x, float $y): bool
    {
        if ($this->inPalette($x)) {
            $max = max(0.0, $this->layoutNow()->paletteHeight - $this->bounds()->h);
            $this->paletteScroll = max(0.0, min($max, $this->paletteScroll - $e->wheelY * 40.0));
            $this->invalidatePaint();

            return true;
        }
        if ($e->modifiers->ctrl) {
            [$cx, $cy] = $this->toCanvas($x, $y);
            $this->zoom = max(self::ZOOM_MIN, min(self::ZOOM_MAX, $this->zoom * (1.0 + 0.1 * $e->wheelY)));
            $this->panX = $cx - ($x - $this->left()) / $this->zoom;
            $this->panY = $cy - $y / $this->zoom;
        } else {
            $this->panX -= $e->wheelX * 30.0 / $this->zoom;
            $this->panY -= $e->wheelY * 30.0 / $this->zoom;
        }
        $this->invalidatePaint();

        return true;
    }

    private function key(SilkEvent $e): bool
    {
        if ($this->list !== null) {
            if ($e->key === Key::ESCAPE) {
                $this->list = null;
                $this->invalidatePaint();
            }

            return true;
        }
        if ($this->editBlock !== 0) {
            switch ($e->key) {
                case Key::ENTER:
                case Key::NUMPAD_ENTER:
                    $this->finishEdit();

                    return true;
                case Key::ESCAPE:
                    $this->cancelEdit();

                    return true;
                case Key::BACKSPACE:
                    $this->editText = $this->editFresh ? '' : mb_substr($this->editText, 0, -1);
                    $this->editFresh = false;
                    $this->invalidatePaint();

                    return true;
                default:
                    return false;
            }
        }
        if ($e->key === Key::ESCAPE && $this->drag === self::CARRY) {
            $this->endCarry(true);
            $this->drag = self::NONE;

            return true;
        }
        if ($e->key === Key::DELETE || $e->key === Key::BACKSPACE) {
            return $this->deleteSelection();
        }
        if ($e->key === Key::A && $e->modifiers->ctrl) {
            $this->selectAll();

            return true;
        }

        return false;
    }

    private function typed(string $text): bool
    {
        if ($this->editBlock === 0 || $text === '') {
            return false;
        }
        $number = ($k = $this->kindOf($this->editBlock)) !== null && ($k->slotTypes[$this->editSlot] ?? '') === 'n';
        $this->editFresh && $this->editText = '';
        $this->editFresh = false;
        $this->editText .= $number ? preg_replace('/[^0-9.\-+eE]/', '', $text) : $text;
        $this->invalidatePaint();

        return true;
    }

    private function kindOf(int $block): ?BlockKind
    {
        return ($b = $this->model->block($block)) !== null ? $this->model->kind($b->kind) : null;
    }

    // ---------------------------------------------------------------- selection and clicks

    private function clickBlock(int $id, bool $add): void
    {
        if ($this->model->block($id) === null) {
            return;
        }
        $this->model->select($id, $add);
        $this->invalidatePaint();
        $this->post(new BlockEditorClicked($this->window(), $this->path(), $this->uuid(), $this->model->topOf($id)));
    }

    private function selectAll(): void
    {
        foreach ($this->model->blocks() as $b) {
            $b->selected = true;
        }
        $this->invalidatePaint();
    }

    private function selectInMarquee(): void
    {
        [$x0, $y0, $x1, $y1] = [min($this->marquee[0], $this->marquee[2]), min($this->marquee[1], $this->marquee[3]), max($this->marquee[0], $this->marquee[2]), max($this->marquee[1], $this->marquee[3])];
        foreach ($this->layoutNow()->drawn as $id) {
            if (($b = $this->model->block($id)) === null) {
                continue;
            }
            [$bx, $by] = $this->shownAt($b);
            $hit = $bx < $x1 && $bx + $b->w > $x0 && $by < $y1 && $by + $b->h > $y0;
            $b->selected = $hit || isset($this->selectedBefore[$id]);
        }
        $this->invalidatePaint();
    }

    private function deleteSelection(): bool
    {
        $selected = $this->model->selected();
        if ($selected === []) {
            return false;
        }
        foreach ($selected as $id) {
            if (($b = $this->model->block($id)) === null) {
                continue;
            }
            if ($b->locked) {
                // it stays; what hangs under it goes if that was selected too
                $b->selected = false;
                ($n = $this->model->block($b->next)) !== null && $n->selected && $this->model->removeBlock($n->id);

                continue;
            }
            $this->model->removeBlock($id);
        }
        $this->changed(0);

        return true;
    }

    // ---------------------------------------------------------------- carrying

    /** Takes the block out with what is under it, and holds it by the point it was pressed at. */
    private function beginCarry(int $id): void
    {
        $lay = $this->layoutNow();
        if (($b = $this->model->block($id)) === null) {
            $this->drag = self::NONE;

            return;
        }
        // (held by the point it was pressed at, not where the pointer is by the time it has moved enough)
        [$cx, $cy] = $this->toCanvas($this->pressX, $this->pressY);
        [$bx, $by] = $this->shownAt($b);
        // a block of a selection of several moves them all
        if ($b->selected && count($this->model->selected()) > 1) {
            $this->beginGroup($cx, $cy);

            return;
        }
        [$this->grabX, $this->grabY] = [$cx - $bx, $cy - $by];
        $this->model->detach($id, $bx, $by);
        $this->model->raise($id);
        $this->model->select($id);
        $this->carried = $id;
        $this->drag = self::CARRY;
        $lay->carry($id);
    }

    private function fromPalette(): void
    {
        $lay = $this->layoutNow();
        foreach ($lay->palette as ['kind' => $kind, 'block' => $pb]) {
            if ($kind !== $this->pressKind) {
                continue;
            }
            [$this->grabX, $this->grabY] = [$this->pressX - $pb->lx, $this->pressY + $this->paletteScroll - $pb->ly];
            [$cx, $cy] = $this->toCanvas($this->pointerX, $this->pointerY);
            $id = $this->model->addBlock($kind, $cx - $this->grabX, $cy - $this->grabY);
            $this->model->select($id);
            $this->carried = $id;
            $this->drag = self::CARRY;
            $lay->carry($id);
            $this->shown[$id] = [(new Spring($cx - $this->grabX, SpringPreset::Snappy))->snap($cx - $this->grabX), (new Spring($cy - $this->grabY, SpringPreset::Snappy))->snap($cy - $this->grabY)];

            return;
        }
        $this->drag = self::NONE;
    }

    /** The stack follows the pointer, and the place it would go opens. */
    private function carryTo(): void
    {
        if (($b = $this->model->block($this->carried)) === null) {
            return;
        }
        [$cx, $cy] = $this->toCanvas($this->pointerX, $this->pointerY);
        [$b->x, $b->y] = [$cx - $this->grabX, $cy - $this->grabY];
        $lay = $this->layoutNow();
        $this->dropAt = $this->inPalette($this->pointerX) ? ['target' => 0, 'place' => BlockPlace::Next, 'index' => 0, 'top' => 0] : $lay->findTarget($this->carried, $b->x, $b->y);
        $lay->carry($this->carried, $this->dropAt['target'], $this->dropAt['place'], $this->dropAt['index'], $this->dropAt['top']);
        $this->invalidatePaint();
    }

    private function endCarry(bool $cancel = false): void
    {
        $id = $this->carried;
        $this->carried = 0;
        $lay = $this->layoutNow();
        $lay->carry(null);
        $d = $this->model->block($id);
        if ($d === null) {
            return;
        }
        $drop = $this->dropAt;
        $this->dropAt = ['target' => 0, 'place' => BlockPlace::Next, 'index' => 0, 'top' => 0];
        if (! $cancel && $this->inPalette($this->pointerX)) {
            if ($d->locked) {
                // it cannot be deleted: back on the canvas
                [$d->x, $d->y] = [$this->toCanvas($this->left() + 20.0, $this->pointerY)[0], $d->y];
                $this->changed($id);

                return;
            }
            $this->model->removeBlock($id);
            $this->changed(0);

            return;
        }
        if (! $cancel && $drop['target'] !== 0) {
            $this->model->attach($id, $drop['target'], $drop['place'], $drop['index']);
        } elseif (! $cancel && $drop['top'] !== 0 && ($below = $this->model->block($drop['top'])) !== null) {
            // above a script: the script hangs under the carried stack, which takes its place over it
            $height = $lay->chainHeight($id);
            [$d->x, $d->y] = [$below->x, $below->y - $height];
            $this->model->attach($below->id, $this->model->lastOfChain($id), BlockPlace::Next);
        }
        $this->changed($id);
    }

    private function beginGroup(float $cx, float $cy): void
    {
        $this->group = [];
        foreach ($this->model->selected() as $id) {
            if (($b = $this->model->block($id)) === null) {
                continue;
            }
            // (a block under a selected one moves with it)
            for ($up = $b->parent; $up !== 0; $up = $this->model->block($up)?->parent ?? 0) {
                if ($this->model->block($up)?->selected) {
                    continue 2;
                }
            }
            [$bx, $by] = $this->shownAt($b);
            $this->model->detach($id, $bx, $by);
            $this->group[$id] = [$bx, $by];
        }
        [$this->grabX, $this->grabY] = [$cx, $cy];
        $this->drag = self::GROUP;
    }

    private function moveGroup(): void
    {
        [$cx, $cy] = $this->toCanvas($this->pointerX, $this->pointerY);
        foreach ($this->group as $id => [$x, $y]) {
            if (($b = $this->model->block($id)) !== null) {
                [$b->x, $b->y] = [$x + $cx - $this->grabX, $y + $cy - $this->grabY];
                $this->shown[$id][0]->snap($b->x);
                $this->shown[$id][1]->snap($b->y);
            }
        }
        $this->layoutNow();
        foreach ($this->group as $id => $_) {
            foreach ($this->model->subtree($id) as $sub) {
                if (($s = $this->model->block($sub)) !== null && isset($this->shown[$sub])) {
                    $this->shown[$sub][0]->snap($s->lx);
                    $this->shown[$sub][1]->snap($s->ly);
                }
            }
        }
        $this->invalidatePaint();
    }

    // ---------------------------------------------------------------- slots, lists and the menu

    private function openSlot(int $block, int $slot): void
    {
        if (($k = $this->kindOf($block)) === null || ($b = $this->model->block($block)) === null || $slot < 0 || $slot >= $k->slotCount) {
            return;
        }
        $this->model->select($block);
        if (($k->options[$slot] ?? []) !== []) {
            [$bx, $by] = $this->shownAt($b);
            $this->list = [
                'kind' => 'choice', 'block' => $block, 'slot' => $slot,
                'x' => $this->left() + ($bx + $b->slotX[$slot] - $this->panX) * $this->zoom,
                'y' => ($by + $b->slotY[$slot] + $b->slotH[$slot] - $this->panY) * $this->zoom + 2.0,
                'rows' => array_map(fn (string $o) => [$o, $o], $k->options[$slot]),
            ];
            $this->invalidatePaint();

            return;
        }
        $this->editBlock = $block;
        $this->editSlot = $slot;
        $this->editText = $b->values[$slot] ?? '';
        $this->editFresh = true;
        $this->invalidatePaint();
    }

    /** What was typed is kept (a number slot keeps a number), also when the user clicks elsewhere. */
    private function finishEdit(): void
    {
        if ($this->editBlock === 0) {
            return;
        }
        $id = $this->editBlock;
        $before = $this->model->value($id, $this->editSlot);
        $this->model->setValue($id, $this->editSlot, $this->editText);
        $this->cancelEdit();
        $this->model->value($id, $this->editSlot) !== $before && $this->changed($id);
    }

    private function cancelEdit(): void
    {
        [$this->editBlock, $this->editSlot, $this->editText] = [0, -1, ''];
        $this->invalidatePaint();
    }

    private function contextAt(float $x, float $y): void
    {
        $lay = $this->layoutNow();
        [$cx, $cy] = $this->toCanvas($x, $y);
        [$id] = $this->inPalette($x) ? [0] : $lay->blockAt($cx, $cy, $this->shownAt(...));
        $this->post(new BlockEditorContext($this->window(), $this->path(), $this->uuid(), $id, $x, $y));
        if (! $this->builtInMenu) {
            return;
        }
        $rows = [];
        if ($id !== 0) {
            $this->model->block($id)?->locked || $rows[] = ['Delete', 'delete'];
            $rows[] = ['Duplicate', 'duplicate'];
        }
        $rows[] = ['Clean up', 'cleanup'];
        $rows[] = ['Select all', 'all'];
        $this->list = ['kind' => 'menu', 'block' => $id, 'slot' => -1, 'x' => $x, 'y' => $y, 'rows' => $rows];
        $this->invalidatePaint();
    }

    /** @return array{float, float, float, float} the box of the open list, in the widget */
    private function listBox(): array
    {
        $rows = $this->list['rows'] ?? [];
        $w = 60.0;
        foreach ($rows as [$label]) {
            $w = max($w, $this->textSize($label, $this->font())->w + 28.0);
        }
        $h = count($rows) * 26.0 + 8.0;
        $b = $this->bounds();

        return [min($this->list['x'], $b->w - $w - 2.0), min($this->list['y'], $b->h - $h - 2.0), $w, $h];
    }

    private function clickList(float $x, float $y): void
    {
        [$bx, $by, $bw, $bh] = $this->listBox();
        $list = $this->list;
        $this->list = null;
        $this->invalidatePaint();
        if ($x < $bx || $x > $bx + $bw || $y < $by + 4.0 || $y > $by + $bh - 4.0) {
            return;
        }
        [, $action] = $list['rows'][(int) (($y - $by - 4.0) / 26.0)] ?? [null, null];
        if ($list['kind'] === 'choice') {
            $changed = $this->model->value($list['block'], $list['slot']) !== $action;
            $action !== null && $this->model->setValue($list['block'], $list['slot'], $action);
            $changed && $action !== null && $this->changed($list['block']);

            return;
        }
        $id = $list['block'];
        switch ($action) {
            case 'delete':
                $this->model->removeBlock($id);
                $this->changed(0);
                break;
            case 'duplicate':
                if (($b = $this->model->block($id)) !== null) {
                    $copy = $this->model->duplicate($id, $b->lx + 30.0, $b->ly + 30.0);
                    $this->model->select($copy);
                    $this->changed($copy);
                }
                break;
            case 'cleanup':
                $this->cleanUp();
                $this->changed(0);
                break;
            case 'all':
                $this->selectAll();
                break;
        }
    }

    /** The scripts in a column at the top left of the canvas, in the order they were made. */
    public function cleanUp(): void
    {
        $lay = $this->layoutNow();
        $y = 20.0;
        foreach ($this->model->scripts() as $top) {
            if (($b = $this->model->block($top)) === null) {
                continue;
            }
            [$b->x, $b->y] = [20.0, $y];
            $this->layoutNow();
            $y += $lay->chainHeight($top) + 24.0;
        }
        $this->invalidatePaint();
    }

    // ---------------------------------------------------------------- paint

    /** The colour of a category: the one given, else one picked from its name. */
    private function categoryColor(string $category): Color
    {
        if (($c = $this->model->categoryColor($category)) !== null) {
            return $c;
        }
        $h = (crc32($category) % 360) / 60.0;
        [$s, $l] = $this->look()->dark() ? [0.5, 0.42] : [0.6, 0.52];
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $f = function (float $t) use ($p, $q): float {
            $t = fmod($t + 6.0, 6.0);

            return $t < 1 ? $p + ($q - $p) * $t : ($t < 3 ? $q : ($t < 4 ? $p + ($q - $p) * (4 - $t) : $p));
        };

        return new Color($f($h + 2), $f($h), $f($h - 2));
    }

    private static function light(Color $c): bool
    {
        return 0.299 * $c->red + 0.587 * $c->green + 0.114 * $c->blue > 0.62;
    }

    private static function shade(Color $c, float $by): Color
    {
        return new Color(max(0.0, min(1.0, $c->red + $by)), max(0.0, min(1.0, $c->green + $by)), max(0.0, min(1.0, $c->blue + $by)), $c->alpha);
    }

    /** A rounded rectangle as a path. */
    private static function rounded(Path $p, float $x, float $y, float $w, float $h, float $r): Path
    {
        $r = min($r, $w / 2, $h / 2);

        return $p->moveTo($x + $r, $y)->lineTo($x + $w - $r, $y)->quadTo($x + $w, $y, $x + $w, $y + $r)->lineTo($x + $w, $y + $h - $r)
            ->quadTo($x + $w, $y + $h, $x + $w - $r, $y + $h)->lineTo($x + $r, $y + $h)->quadTo($x, $y + $h, $x, $y + $h - $r)
            ->lineTo($x, $y + $r)->quadTo($x, $y, $x + $r, $y)->close();
    }

    /** The outline of a block at x, y: a pill, a hexagon, or a step with a notch on top and a tab under (a C block wraps its mouths). */
    private function outline(BlockKind $k, Block $b, float $x, float $y): Path
    {
        $w = $b->w;
        $h = $b->h;
        $r = 5.0;
        if ($k->shape === BlockShape::Reporter) {
            return self::rounded(new Path, $x, $y, $w, $h, $h / 2);
        }
        if ($k->shape === BlockShape::Boolean) {
            $t = $h / 2;

            return (new Path)->moveTo($x, $y + $t)->lineTo($x + $t, $y)->lineTo($x + $w - $t, $y)->lineTo($x + $w, $y + $t)->lineTo($x + $w - $t, $y + $h)->lineTo($x + $t, $y + $h)->close();
        }
        $nx = 14.0;
        $nw = 18.0;
        $nd = 5.0;
        $tab = fn (Path $p, float $px, float $py) => $p->lineTo($px + $nx + $nw, $py)->lineTo($px + $nx + $nw - $nd, $py + $nd)->lineTo($px + $nx + $nd, $py + $nd)->lineTo($px + $nx, $py);
        $p = new Path;
        if ($k->shape === BlockShape::Hat) {
            $p->moveTo($x, $y + 14.0)->cubicTo($x + 6.0, $y + 1.0, $x + 58.0, $y + 1.0, $x + 70.0, $y + 14.0)->lineTo($x + $w - $r, $y + 14.0);
            $p->quadTo($x + $w, $y + 14.0, $x + $w, $y + 14.0 + $r);
        } else {
            $p->moveTo($x + $r, $y);
            if ($k->shape->hasTop()) {
                $p->lineTo($x + $nx, $y)->lineTo($x + $nx + $nd, $y + $nd)->lineTo($x + $nx + $nw - $nd, $y + $nd)->lineTo($x + $nx + $nw, $y);
            }
            $p->lineTo($x + $w - $r, $y)->quadTo($x + $w, $y, $x + $w, $y + $r);
        }
        if ($k->shape === BlockShape::C) {
            for ($s = 0; $s < $k->sections; $s++) {
                $rowBottom = $y + $b->rowY[$s] + $b->rowH[$s];
                $mouthBottom = $y + $b->mouthY[$s] + $b->mouthH[$s];
                $p->lineTo($x + $w, $rowBottom)->lineTo($x + BlockLayout::ARM + $nx + $nw, $rowBottom);
                $p->lineTo($x + BlockLayout::ARM + $nx + $nw - $nd, $rowBottom + $nd)->lineTo($x + BlockLayout::ARM + $nx + $nd, $rowBottom + $nd)->lineTo($x + BlockLayout::ARM + $nx, $rowBottom);
                $p->lineTo($x + BlockLayout::ARM, $rowBottom)->lineTo($x + BlockLayout::ARM, $mouthBottom)->lineTo($x + $w, $mouthBottom);
            }
        }
        $p->lineTo($x + $w, $y + $h - $r)->quadTo($x + $w, $y + $h, $x + $w - $r, $y + $h);
        if ($k->shape->hasNext()) {
            $p->lineTo($x + $nx + $nw, $y + $h)->lineTo($x + $nx + $nw - $nd, $y + $h + $nd)->lineTo($x + $nx + $nd, $y + $h + $nd)->lineTo($x + $nx, $y + $h);
        }
        $p->lineTo($x + $r, $y + $h)->quadTo($x, $y + $h, $x, $y + $h - $r)->lineTo($x, ($k->shape === BlockShape::Hat ? $y + 14.0 : $y) + $r);
        if ($k->shape !== BlockShape::Hat) {
            $p->quadTo($x, $y, $x + $r, $y);
        }

        return $p->close();
    }

    public function paint(Painter $p): void
    {
        $look = $this->look();
        $b = $this->bounds();
        $lay = $this->layoutNow();
        $this->followLayout($lay);
        $dark = $look->dark();
        $font = $this->font();
        $enabled = $this->isEnabled();

        $p->rect($b, $this->color(Role::Input, $look), 6.0, $this->color(Role::Border, $look), 1.0);
        $p->pushClip(new Rect($b->x + 1, $b->y + 1, $b->w - 2, $b->h - 2));

        // ---- the canvas
        $left = $this->left();
        $area = new Rect($b->x + $left, $b->y, $b->w - $left, $b->h);
        $p->pushClip($area);
        $this->paintGrid($p, $area, $dark);
        $p->pushTransform($this->zoom, $b->x + $left - $this->panX * $this->zoom, $b->y - $this->panY * $this->zoom);
        $ghost = $this->carried !== 0 ? $this->model->block($this->carried) : null;
        if ($ghost !== null && ($this->dropAt['target'] !== 0 || $this->dropAt['top'] !== 0)) {
            $this->paintGhost($p, $ghost, $lay->ghostX, $lay->ghostY);
        }
        foreach ($lay->drawn as $id) {
            if (($blk = $this->model->block($id)) !== null) {
                $this->paintBlock($p, $blk, $font, $id === $this->carried || ($this->carried !== 0 && $this->model->inside($id, $this->carried)));
            }
        }
        if ($this->drag === self::MARQUEE) {
            [$x0, $y0, $x1, $y1] = [min($this->marquee[0], $this->marquee[2]), min($this->marquee[1], $this->marquee[3]), max($this->marquee[0], $this->marquee[2]), max($this->marquee[1], $this->marquee[3])];
            $accent = $this->color(Role::Accent, $look);
            $p->rect(new Rect($x0, $y0, $x1 - $x0, $y1 - $y0), new Color($accent->red, $accent->green, $accent->blue, 0.14), 2.0, new Color($accent->red, $accent->green, $accent->blue, 0.85), 1.0 / $this->zoom);
        }
        $p->popTransform();
        $p->popClip();

        // ---- the palette
        if ($this->paletteVisible) {
            $this->paintPalette($p, $b, $lay, $font);
        }
        $this->list !== null && $this->paintList($p, $b, $font);
        if ($this->hasFocus && $enabled) {
            $p->rect($b->grow(2), new Color(0, 0, 0, 0), 8.0, $this->color(Role::Focus, $look), 2.0);
        }
        $p->popClip();
        $this->keepTicking(array_filter($this->model->blocks(), fn (Block $x) => $x->glow) !== []);
        $this->painted = true;
    }

    /** A grid of faint lines every 24 canvas units, left out when they would be closer than 10 pixels. */
    private function paintGrid(Painter $p, Rect $area, bool $dark): void
    {
        $step = 24.0 * $this->zoom;
        if ($step < 10.0) {
            return;
        }
        $c = $this->color(Role::Text, $this->look());
        $faint = new Color($c->red, $c->green, $c->blue, 0.07);
        $x = $area->x + fmod(-$this->panX * $this->zoom, $step);
        $x < $area->x && $x += $step;
        for (; $x < $area->x + $area->w; $x += $step) {
            $p->line($x, $area->y, $x, $area->y + $area->h, $faint, 1.0);
        }
        $y = $area->y + fmod(-$this->panY * $this->zoom, $step);
        $y < $area->y && $y += $step;
        for (; $y < $area->y + $area->h; $y += $step) {
            $p->line($area->x, $y, $area->x + $area->w, $y, $faint, 1.0);
        }
    }

    private function paintGhost(Painter $p, Block $held, float $gx, float $gy): void
    {
        $k = $this->model->kind($held->kind);
        $slotTarget = $this->dropAt['place'] === BlockPlace::Input && $this->dropAt['target'] !== 0;
        $accent = $this->color(Role::Accent, $this->look());
        if ($slotTarget && ($t = $this->model->block($this->dropAt['target'])) !== null) {
            [$tx, $ty] = $this->shownAt($t);
            $s = $this->dropAt['index'];
            $p->rect(new Rect($tx + $t->slotX[$s] - 2, $ty + $t->slotY[$s] - 2, $t->slotW[$s] + 4, $t->slotH[$s] + 4), new Color($accent->red, $accent->green, $accent->blue, 0.25), 6.0, $accent, 2.0);

            return;
        }
        $p->fill($this->outline($k, $held, $gx, $gy), new Color(0, 0, 0, 0.28));
    }

    /** One block at its shown place: its shape, its words and slots; the blocks in its slots and mouths are laid out apart and painted on their own. */
    private function paintBlock(Painter $p, Block $b, FontSpec $font, bool $held): void
    {
        $k = $this->model->kind($b->kind);
        [$x, $y] = $this->shownAt($b);
        $this->paintShape($p, $k, $b, $x, $y, $font, $held);
    }

    private function paintShape(Painter $p, BlockKind $k, Block $b, float $x, float $y, FontSpec $font, bool $held, float $alpha = 1.0): void
    {
        $look = $this->look();
        $base = $this->categoryColor($k->category);
        $edge = self::shade($base, -0.14);
        $text = self::light($base) ? new Color(0.1, 0.1, 0.12, 1) : new Color(1, 1, 1, 1);
        $value = $k->shape->isValue();
        $path = $this->outline($k, $b, $x, $y);
        if ($b->glow) {
            $breath = 0.5 + 0.5 * sin($this->glowTime * 4.2);
            $halo = $this->color(Role::Warning, $look);
            $p->shadow(new Rect($x, $y, $b->w, $b->h), 8.0, 10.0 + 8.0 * $breath, new Color($halo->red, $halo->green, $halo->blue, 0.45 + 0.4 * $breath));
        }
        $held && $p->shadow(new Rect($x, $y, $b->w, $b->h), 6.0, 10.0, new Color(0, 0, 0, 0.35), 0.0, 4.0);
        $p->fill($path, $base);
        $p->stroke($path, $b->selected ? $this->color(Role::Accent, $look) : $edge, new StrokeStyle($b->selected ? 2.5 : 1.0));
        for ($s = 0; $s < $k->sections; $s++) {
            $lay = $this->lay;
            $px = $value ? ($k->shape === BlockShape::Boolean ? $lay->slot * 0.55 + 6.0 : $lay->pad + 2.0) : $lay->pad;
            $lift = $k->shape === BlockShape::Hat && $s === 0 ? 14.0 : 0.0;
            $row = new Rect($x, $y + $b->rowY[$s] + $lift, $b->w, $b->rowH[$s] - $lift);
            foreach ($k->tokens as $t) {
                if ($t['section'] !== $s) {
                    continue;
                }
                if ($t['slot'] < 0) {
                    $tw = $this->textSize((string) $t['text'], $font)->w;
                    $p->text((string) $t['text'], $x + $px, $this->baselineIn($row, $font), $text, $font);
                    $px += $tw + 4.0;

                    continue;
                }
                $px = $b->slotX[$t['slot']] + $b->slotW[$t['slot']] + 5.0;
                ($b->input[$t['slot']] ?? 0) === 0 && $this->paintSlot($p, $k, $b, $t['slot'], $x, $y, $font);
            }
        }
    }

    private function paintSlot(Painter $p, BlockKind $k, Block $b, int $slot, float $x, float $y, FontSpec $font): void
    {
        $look = $this->look();
        $rect = new Rect($x + $b->slotX[$slot], $y + $b->slotY[$slot], $b->slotW[$slot], $b->slotH[$slot]);
        $editing = $this->editBlock === $b->id && $this->editSlot === $slot;
        $fill = $this->look()->dark() ? new Color(0.96, 0.96, 0.98, 1) : new Color(1, 1, 1, 1);
        $ink = new Color(0.12, 0.12, 0.14, 1);
        if ($k->slotTypes[$slot] === 'b') {
            $t = $rect->h / 2;
            $p->fill((new Path)->moveTo($rect->x, $rect->y + $t)->lineTo($rect->x + $t, $rect->y)->lineTo($rect->x + $rect->w - $t, $rect->y)->lineTo($rect->x + $rect->w, $rect->y + $t)->lineTo($rect->x + $rect->w - $t, $rect->y + $rect->h)->lineTo($rect->x + $t, $rect->y + $rect->h)->close(), new Color(0, 0, 0, 0.28));

            return;
        }
        $p->rect($rect, $fill, $rect->h / 2, $editing ? $this->color(Role::Accent, $look) : null, $editing ? 2.0 : 0.0);
        $shown = $editing ? $this->editText.($this->hasFocus ? '|' : '') : ($b->values[$slot] ?? '');
        $tw = $this->textSize($shown, $font)->w;
        $p->text($shown, $rect->x + ($rect->w - $tw - ($k->slotTypes[$slot] === 'm' ? 12.0 : 0.0)) / 2, $this->baselineIn($rect, $font), $ink, $font);
        if ($k->slotTypes[$slot] === 'm') {
            $cx = $rect->x + $rect->w - 12.0;
            $cy = $rect->y + $rect->h / 2;
            $p->triangle($cx - 4.0, $cy - 2.0, $cx + 4.0, $cy - 2.0, $cx, $cy + 3.0, $ink);
        }
    }

    private function paintPalette(Painter $p, Rect $b, BlockLayout $lay, FontSpec $font): void
    {
        $look = $this->look();
        $area = new Rect($b->x, $b->y, BlockLayout::PALETTE_W, $b->h);
        $p->rect($area, $this->color(Role::Panel, $look), [6.0, 0.0, 0.0, 6.0]);
        $p->line($b->x + BlockLayout::PALETTE_W, $b->y + 1, $b->x + BlockLayout::PALETTE_W, $b->y + $b->h - 1, $this->color(Role::Border, $look), 1.0);
        $p->pushClip($area);
        $p->pushTransform(1.0, $b->x, $b->y - $this->paletteScroll);
        $muted = $this->color(Role::TextDisabled, $look);
        $bold = new FontSpec($font->family, $font->size, 700, $font->italic);
        foreach ($lay->headers as ['text' => $text, 'y' => $y]) {
            $p->text($text, 14.0, $this->baselineIn(new Rect(14.0, $y, 150.0, 20.0), $bold), $muted, $bold);
        }
        foreach ($lay->palette as ['kind' => $kind, 'block' => $blk]) {
            $this->paintShape($p, $this->model->kind($kind), $blk, $blk->lx, $blk->ly, $font, false);
        }
        $p->popTransform();
        $p->popClip();
        // dropped here, a block is deleted
        if ($this->carried !== 0 && $this->inPalette($this->pointerX)) {
            $danger = $this->color(Role::Danger, $look);
            $p->rect($area, new Color($danger->red, $danger->green, $danger->blue, 0.18), [6.0, 0.0, 0.0, 6.0], $danger, 2.0);
        }
    }

    private function paintList(Painter $p, Rect $b, FontSpec $font): void
    {
        $look = $this->look();
        [$lx, $ly, $lw, $lh] = $this->listBox();
        $p->shadow(new Rect($b->x + $lx, $b->y + $ly, $lw, $lh), 8.0, 14.0, new Color(0, 0, 0, 0.3), 0.0, 4.0);
        $p->rect(new Rect($b->x + $lx, $b->y + $ly, $lw, $lh), $this->color(Role::Popup, $look), 8.0, $this->color(Role::PopupBorder, $look), 1.0);
        foreach ($this->list['rows'] as $i => [$label]) {
            $row = new Rect($b->x + $lx + 6.0, $b->y + $ly + 4.0 + $i * 26.0, $lw - 12.0, 26.0);
            $current = $this->list['kind'] === 'choice' && $this->model->value($this->list['block'], $this->list['slot']) === $label;
            $current && $p->rect($row, $this->color(Role::RowHovered, $look), 5.0);
            $p->text($label, $row->x + 8.0, $this->baselineIn($row, $font), $this->color(Role::Text, $look), $font);
        }
    }

    /** The running blocks' halos breathe: a ticker steps their clock while any glows. */
    private function keepTicking(bool $wanted): void
    {
        if (! $wanted || $this->ticking || $this->tree() === null) {
            return;
        }
        $this->ticking = true;
        $this->tree()->tick(function (float $dt): bool {
            $this->glowTime = fmod($this->glowTime + $dt, 4096.0);
            $this->invalidatePaint();
            $this->ticking = array_filter($this->model->blocks(), fn (Block $x) => $x->glow) !== [];

            return $this->ticking;
        });
    }
}
