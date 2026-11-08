<?php

namespace Silk\Widgets\Blocks;

use Closure;

/**
 * Where every block of a model is, in canvas units: sizes from the text widths, places from the sizes, the gap a carried
 * stack opens where it would go, the palette (one block of each visible kind, by category), what is under a point, and where
 * a carried stack would land. The widget supplies the text width; a test supplies any function.
 */
final class BlockLayout
{
    public const float PALETTE_W = 210.0;

    /** How near (canvas units) a carried block must come to a place to snap there. */
    public const float SNAP = 34.0;

    /** The width of the arm of a C block, left of its mouths. */
    public const float ARM = 18.0;

    public const float BOTTOM = 18.0;

    public const float MOUTH_MIN = 26.0;

    /** @var list<int> the blocks that are placed, back to front (the order a click looks through, reversed) */
    public array $drawn = [];

    /** Where the carried stack's shadow waits. */
    public float $ghostX = 0.0;

    public float $ghostY = 0.0;

    /** @var list<array{kind: string, block: Block}> the palette, by category */
    public array $palette = [];

    /** @var list<array{text: string, y: float}> */
    public array $headers = [];

    public float $paletteHeight = 0.0;

    /** @var array{id: int, height: float, target: int, place: BlockPlace, index: int, top: int}|null the stack in the hand and where it would go */
    private ?array $carry = null;

    /** @var array<string, Block> */
    private array $paletteBlocks = [];

    public readonly float $slot;

    public readonly float $row;

    public readonly float $pad;

    /** @param Closure(string): float $textWidth the width of a text in the editor's font */
    public function __construct(private readonly BlockModel $model, private readonly Closure $textWidth, public readonly float $font = 14.0)
    {
        $this->slot = floor($font + 10.0);
        $this->row = max(36.0, $this->slot + 12.0);
        $this->pad = 10.0;
    }

    /** The stack in the hand: its id, where it would go ($target, $place, $index; or $top: above the script that starts there), or null. */
    public function carry(?int $id, int $target = 0, BlockPlace $place = BlockPlace::Next, int $index = 0, int $top = 0): void
    {
        $this->carry = $id === null ? null : ['id' => $id, 'height' => $this->chainHeight($id), 'target' => $target, 'place' => $place, 'index' => $index, 'top' => $top];
    }

    public function carried(): int
    {
        return $this->carry['id'] ?? 0;
    }

    /** The height of the chain that starts at $id, under it included (as last laid out). */
    public function chainHeight(int $id): float
    {
        $h = 0.0;
        for ($b = $this->model->block($id), $guard = 0; $b !== null && $guard++ < 100000; $b = $this->model->block($b->next)) {
            $h += $b->h;
        }

        return $h;
    }

    // ---------------------------------------------------------------- measure and place

    private function width(string $text): float
    {
        return ($this->textWidth)($text);
    }

    /** @return array{float, float} width and height of a chain of steps (a mouth, a script) */
    private function measureChain(int $first): array
    {
        $w = $h = 0.0;
        for ($b = $this->model->block($first), $guard = 0; $b !== null && $guard++ < 100000; $b = $this->model->block($b->next)) {
            $this->measureBlock($b);
            $w = max($w, $b->w);
            $h += $b->h;
            $this->carry !== null && $this->carry['target'] === $b->id && $this->carry['place'] === BlockPlace::Next && $h += $this->carry['height'];
        }

        return [$w, $h];
    }

    /** @return array{float, float} the width and height of the value in a slot, or of an empty one */
    private function slotExtent(Block $b, BlockKind $k, int $slot): array
    {
        if (($input = $this->model->block($b->input[$slot] ?? 0)) !== null) {
            $this->measureBlock($input);

            return [$input->w, $input->h];
        }
        if ($k->slotTypes[$slot] === 'b') {
            return [40.0, $this->slot];
        }

        return [max($this->slot + 4.0, $this->width($b->values[$slot] ?? '') + ($k->slotTypes[$slot] === 'm' ? 30.0 : 16.0)), $this->slot];
    }

    public function measureBlock(Block $b): void
    {
        if (($k = $this->model->kind($b->kind)) === null) {
            return;
        }
        $value = $k->shape->isValue();
        $bool = $k->shape === BlockShape::Boolean;
        $hat = $k->shape === BlockShape::Hat;
        $width = $y = 0.0;
        for ($s = 0; $s < $k->sections; $s++) {
            $x = $value ? ($bool ? $this->slot * 0.55 + 6.0 : $this->pad + 2.0) : $this->pad;
            $row = $value ? $this->slot : $this->row;
            foreach ($k->tokens as $t) {
                if ($t['section'] !== $s) {
                    continue;
                }
                if ($t['slot'] < 0) {
                    $x += $this->width((string) $t['text']) + 4.0;

                    continue;
                }
                [$sw, $sh] = $this->slotExtent($b, $k, $t['slot']);
                $b->slotX[$t['slot']] = $x;
                $b->slotW[$t['slot']] = $sw;
                $b->slotH[$t['slot']] = $sh;
                $row = max($row, $sh + ($value ? 6.0 : 10.0));
                $x += $sw + 5.0;
            }
            $hat && $s === 0 && $row += 14.0;
            $x += $value ? ($bool ? $this->slot * 0.55 + 2.0 : $this->pad - 2.0) : $this->pad;
            $width = max($width, $x);
            $b->rowY[$s] = $y;
            $b->rowH[$s] = $row;
            $lift = $hat && $s === 0 ? 14.0 : 0.0;
            foreach ($k->tokens as $t) {
                $t['section'] === $s && $t['slot'] >= 0 && $b->slotY[$t['slot']] = $y + $lift + (($row - $lift) - $b->slotH[$t['slot']]) * 0.5;
            }
            $y += $row;
            if ($k->shape === BlockShape::C) {
                [, $mh] = $this->measureChain($b->body[$s] ?? 0);
                $this->carry !== null && $this->carry['target'] === $b->id && $this->carry['place'] === BlockPlace::Body && $this->carry['index'] === $s && $mh += $this->carry['height'];
                $b->mouthY[$s] = $y;
                $b->mouthH[$s] = max(self::MOUTH_MIN, $mh);
                $y += $b->mouthH[$s];
                $width = max($width, self::ARM + 60.0);
            }
        }
        $k->shape === BlockShape::C && $y += self::BOTTOM;
        $b->w = max($width, $value ? $this->slot : 64.0);
        $b->h = $y;
    }

    private function placeChain(int $first, float $x, float $y): void
    {
        for ($b = $this->model->block($first), $guard = 0; $b !== null && $guard++ < 100000; $b = $this->model->block($b->next)) {
            $this->placeBlock($b, $x, $y);
            $y += $b->h;
            if ($this->carry !== null && $this->carry['target'] === $b->id && $this->carry['place'] === BlockPlace::Next) {
                [$this->ghostX, $this->ghostY] = [$x, $y];
                $y += $this->carry['height'];
            }
        }
    }

    private function placeBlock(Block $b, float $x, float $y): void
    {
        if (($k = $this->model->kind($b->kind)) === null) {
            return;
        }
        [$b->lx, $b->ly] = [$x, $y];
        $this->drawn[] = $b->id;
        for ($s = 0; $s < $k->slotCount; $s++) {
            ($input = $this->model->block($b->input[$s] ?? 0)) !== null && $this->placeBlock($input, $x + $b->slotX[$s], $y + $b->slotY[$s]);
        }
        if ($k->shape === BlockShape::C) {
            for ($s = 0; $s < $k->sections; $s++) {
                $my = $y + $b->mouthY[$s];
                if ($this->carry !== null && $this->carry['target'] === $b->id && $this->carry['place'] === BlockPlace::Body && $this->carry['index'] === $s) {
                    [$this->ghostX, $this->ghostY] = [$x + self::ARM, $my];
                    $my += $this->carry['height'];
                }
                $this->placeChain($b->body[$s] ?? 0, $x + self::ARM, $my);
            }
        }
    }

    /** Sizes first (they include the gap the carried stack opens), then places, then the palette. */
    public function layout(): void
    {
        $this->drawn = [];
        foreach ($this->model->scripts() as $top) {
            $this->measureChain($top);
        }
        foreach ($this->model->scripts() as $top) {
            if (($b = $this->model->block($top)) === null) {
                continue;
            }
            [$x, $y] = [$b->x, $b->y];
            if ($this->carry !== null && $this->carry['top'] === $top) {
                [$this->ghostX, $this->ghostY] = [$x, $y];
                $y += $this->carry['height'];
            }
            $this->placeChain($top, $x, $y);
        }
        $this->layoutPalette();
    }

    /** One block of each visible kind, by category (each category once, in the order it first appears), under its heading. */
    private function layoutPalette(): void
    {
        $this->palette = $this->headers = [];
        $y = 10.0;
        $byCategory = [];
        $index = [];
        $i = 0;
        foreach ($this->model->kinds() as $name => $k) {
            $index[$name] = ++$i;
            $k->hidden || $byCategory[$k->category][] = $name;
        }
        foreach ($byCategory as $category => $names) {
            if ($category !== '') {
                $this->headers[] = ['text' => (string) $category, 'y' => $y + 4.0];
                $y += 30.0;
            } else {
                $y += 6.0;
            }
            foreach ($names as $name) {
                $b = $this->paletteBlocks[$name] ??= $this->paletteBlock($name, -$index[$name]);
                $b->values = $this->model->kind($name)->defaults;
                $this->measureBlock($b);
                [$b->lx, $b->ly] = [14.0, $y];
                $this->palette[] = ['kind' => $name, 'block' => $b];
                $y += $b->h + 10.0;
            }
        }
        $this->paletteHeight = $y + 20.0;
    }

    private function paletteBlock(string $kind, int $id): Block
    {
        $b = new Block($id, $kind);
        $b->values = $this->model->kind($kind)?->defaults ?? [];

        return $b;
    }

    // ---------------------------------------------------------------- hit tests

    /** Whether the point is on the block itself, not in one of its mouths. */
    public function onBlock(Block $b, float $bx, float $by, float $cx, float $cy): bool
    {
        if (($k = $this->model->kind($b->kind)) === null || $cx < $bx || $cy < $by || $cx > $bx + $b->w || $cy > $by + $b->h) {
            return false;
        }
        if ($k->shape === BlockShape::C) {
            for ($s = 0; $s < $k->sections; $s++) {
                if ($cx > $bx + self::ARM && $cy > $by + $b->mouthY[$s] && $cy < $by + $b->mouthY[$s] + $b->mouthH[$s]) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * The block under a canvas point, the deepest first (what is drawn last wins), and the empty value slot under it (-1: none).
     * $at gives the place a block is shown at (default: where the layout put it); the stack in the hand is passed over.
     *
     * @param  (Closure(Block): array{float, float})|null  $at
     * @return array{int, int} block id (0: none) and slot
     */
    public function blockAt(float $cx, float $cy, ?Closure $at = null): array
    {
        $carried = $this->carried();
        for ($i = count($this->drawn); $i > 0; $i--) {
            $b = $this->model->block($this->drawn[$i - 1]);
            if ($b === null || ($carried !== 0 && $this->model->inside($b->id, $carried))) {
                continue;
            }
            [$bx, $by] = $at !== null ? $at($b) : [$b->lx, $b->ly];
            if (! $this->onBlock($b, $bx, $by, $cx, $cy)) {
                continue;
            }
            $k = $this->model->kind($b->kind);
            $slot = -1;
            for ($s = 0; $s < $k->slotCount; $s++) {
                $sx = $bx + $b->slotX[$s];
                $sy = $by + $b->slotY[$s];
                if (($b->input[$s] ?? 0) === 0 && $cx >= $sx && $cx <= $sx + $b->slotW[$s] && $cy >= $sy && $cy <= $sy + $b->slotH[$s]) {
                    $slot = $s;
                }
            }

            return [$b->id, $slot];
        }

        return [0, -1];
    }

    /** The kind whose palette block is under a point of the palette (its own units, scrolled), or null. */
    public function paletteAt(float $px, float $py): ?string
    {
        foreach ($this->palette as ['kind' => $kind, 'block' => $b]) {
            if ($this->onBlock($b, $b->lx, $b->ly, $px, $py)) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * Where the stack in the hand would go if released with its top left at the canvas point: the nearest place within SNAP
     * that takes it. A value goes to an empty slot that takes its shape (measured to the slot's left middle); a step goes under a
     * block, into a mouth, or over the top of a script it could sit above. Nothing near: no target.
     *
     * @return array{target: int, place: BlockPlace, index: int, top: int}
     */
    public function findTarget(int $carried, float $dx, float $dy): array
    {
        $none = ['target' => 0, 'place' => BlockPlace::Next, 'index' => 0, 'top' => 0];
        if (($d = $this->model->block($carried)) === null || ($kd = $this->model->kind($d->kind)) === null) {
            return $none;
        }
        $best = self::SNAP * self::SNAP;
        $target = $index = $top = 0;
        $place = BlockPlace::Next;
        $last = $this->model->block($this->model->lastOfChain($carried));
        $kl = $last !== null ? $this->model->kind($last->kind) : null;
        // a stack that cannot continue (it ends in a cap) only goes where nothing hangs yet
        $continues = $kl !== null && $kl->shape->hasNext();
        $near = fn (float $px, float $py): float => ($dx - $px) ** 2 + ($dy - $py) ** 2;
        foreach ($this->drawn as $id) {
            if (($t = $this->model->block($id)) === null || ($kt = $this->model->kind($t->kind)) === null || $this->model->inside($t->id, $carried)) {
                continue;
            }
            if ($kd->shape->isValue()) {
                for ($s = 0; $s < $kt->slotCount; $s++) {
                    if (($t->input[$s] ?? 0) !== 0 || ! $this->model->slotTakes($kt, $s, $kd->shape)) {
                        continue;
                    }
                    $dist = ($dx - ($t->lx + $t->slotX[$s])) ** 2 + ($dy + $d->h * 0.5 - ($t->ly + $t->slotY[$s] + $t->slotH[$s] * 0.5)) ** 2;
                    $dist < $best && [$best, $target, $place, $index] = [$dist, $t->id, BlockPlace::Input, $s];
                }

                continue;
            }
            if ($kd->shape === BlockShape::Hat || $kt->shape->isValue()) {
                continue;
            }
            if ($kt->shape->hasNext() && ($t->next === 0 || $continues) && ($dist = $near($t->lx, $t->ly + $t->h)) < $best) {
                [$best, $target, $place, $index] = [$dist, $t->id, BlockPlace::Next, 0];
            }
            if ($kt->shape === BlockShape::C) {
                for ($s = 0; $s < $kt->sections; $s++) {
                    if (($t->body[$s] ?? 0) !== 0 && ! $continues) {
                        continue;
                    }
                    ($dist = $near($t->lx + self::ARM, $t->ly + $t->mouthY[$s])) < $best && [$best, $target, $place, $index] = [$dist, $t->id, BlockPlace::Body, $s];
                }
            }
        }
        // above a script: its top takes a stack over it
        if ($continues && ! $kd->shape->isValue()) {
            $height = $this->chainHeight($carried);
            foreach ($this->model->scripts() as $id) {
                if (($t = $this->model->block($id)) === null || $id === $carried || ($kt = $this->model->kind($t->kind)) === null || ! $kt->shape->hasTop()) {
                    continue;
                }
                if (($dist = ($dx - $t->x) ** 2 + ($dy + $height - $t->y) ** 2) < $best) {
                    [$best, $top, $target] = [$dist, $id, 0];
                }
            }
        }

        return ['target' => $top !== 0 ? 0 : $target, 'place' => $place, 'index' => $index, 'top' => $top];
    }
}
