<?php

namespace Silk\Widgets\Blocks;

use InvalidArgumentException;
use Surface\NutsAndBolts\Color;

/**
 * The scripts of a block editor, without a screen: kinds, blocks, where each hangs, and the rules of what may go where.
 * Blocks are named by integers above 0. A block hangs under another (Next), in a mouth of a C block (Body, index = the
 * mouth) or in a value slot (Input, index = the slot), or is the top of a script of its own at x, y. Changing the model
 * does not tell anyone: the editor posts mail for what the user does, and `revision()` moves for every change.
 */
final class BlockModel
{
    /** @var array<string, BlockKind> by name, in the order they were added */
    private array $kinds = [];

    /** @var array<int, Block> */
    private array $blocks = [];

    /** @var list<int> the tops of the scripts, back to front */
    private array $order = [];

    /** @var array<string, Color> */
    private array $categoryColors = [];

    private int $nextId = 1;

    private int $revision = 0;

    // ---------------------------------------------------------------- kinds

    /** A kind of block, and its block in the palette. @return int its index, -1 when the name is taken or empty */
    public function addKind(string $name, string $label, BlockShape $shape, string $category = ''): int
    {
        if ($name === '' || isset($this->kinds[$name])) {
            return -1;
        }
        $this->kinds[$name] = new BlockKind($name, $label, $shape, $category);
        $this->revision++;

        return count($this->kinds) - 1;
    }

    public function kind(string $name): ?BlockKind
    {
        return $this->kinds[$name] ?? null;
    }

    /** @return array<string, BlockKind> */
    public function kinds(): array
    {
        return $this->kinds;
    }

    public function setCategoryColor(string $category, ?Color $color): void
    {
        $color === null ? $this->categoryColors = array_diff_key($this->categoryColors, [$category => 1]) : $this->categoryColors[$category] = $color;
        $this->revision++;
    }

    public function categoryColor(string $category): ?Color
    {
        return $this->categoryColors[$category] ?? null;
    }

    /** The choices of a %m slot of a kind; none: the slot is typed in. */
    public function setSlotOptions(string $kind, int $slot, array $options): void
    {
        if (($k = $this->kind($kind)) === null || $slot < 0 || $slot >= $k->slotCount) {
            throw new InvalidArgumentException("Kind '{$kind}' has no slot {$slot}.");
        }
        $k->options[$slot] = array_values(array_map('strval', $options));
        $this->revision++;
    }

    /** False: the kind is not in the palette (its blocks stay where they are). */
    public function setKindVisible(string $kind, bool $visible): void
    {
        ($k = $this->kind($kind)) !== null && $k->hidden = ! $visible;
        $this->revision++;
    }

    /**
     * A new label for a kind (a function that takes one more input...). Its blocks keep the values of the slots that are
     * still there; what hung in a slot or a mouth that is gone is taken out beside its block.
     */
    public function setKindLabel(string $kind, string $label): bool
    {
        if (($k = $this->kind($kind)) === null) {
            return false;
        }
        $k->label = $label;
        $k->parse();
        foreach ($this->blocks as $b) {
            if ($b->kind !== $kind) {
                continue;
            }
            foreach ($b->input as $slot => $id) {
                $slot >= $k->slotCount && $this->makeTop($id, $b->x + 40.0, $b->y + 40.0);
            }
            foreach ($b->body as $mouth => $id) {
                $mouth >= $k->sections && $this->makeTop($id, $b->x + 40.0, $b->y + 40.0);
            }
            $values = [];
            for ($s = 0; $s < $k->slotCount; $s++) {
                $values[$s] = $b->values[$s] ?? $k->defaults[$s];
            }
            $b->values = $values;
        }
        $this->revision++;

        return true;
    }

    // ---------------------------------------------------------------- blocks

    /** A new block, the top of a script of its own at x, y (canvas units). @return int 0 when the kind is unknown */
    public function addBlock(string $kind, float $x = 0.0, float $y = 0.0): int
    {
        if (($k = $this->kind($kind)) === null) {
            return 0;
        }
        $b = new Block($this->nextId++, $kind);
        $b->x = $x;
        $b->y = $y;
        $b->values = $k->defaults;
        $this->blocks[$b->id] = $b;
        $this->order[] = $b->id;
        $this->revision++;

        return $b->id;
    }

    public function block(int $id): ?Block
    {
        return $this->blocks[$id] ?? null;
    }

    public function exists(int $id): bool
    {
        return isset($this->blocks[$id]);
    }

    /** @return array<int, Block> */
    public function blocks(): array
    {
        return $this->blocks;
    }

    public function count(): int
    {
        return count($this->blocks);
    }

    /** Removes a block and everything that hangs under it. */
    public function removeBlock(int $id): void
    {
        if (($b = $this->block($id)) === null) {
            return;
        }
        $this->unhook($b);
        foreach ($this->subtree($id) as $gone) {
            unset($this->blocks[$gone]);
        }
        $this->revision++;
    }

    public function clear(): void
    {
        $this->blocks = [];
        $this->order = [];
        $this->revision++;
    }

    /** Every block that hangs from $id, itself first: under it, in its mouths, in its slots. @return list<int> */
    public function subtree(int $id): array
    {
        $out = [];
        $walk = function (int $at) use (&$walk, &$out): void {
            if (($b = $this->block($at)) === null || in_array($at, $out, true)) {
                return;
            }
            $out[] = $at;
            $b->next !== 0 && $walk($b->next);
            foreach ($b->body as $first) {
                $first !== 0 && $walk($first);
            }
            foreach ($b->input as $value) {
                $value !== 0 && $walk($value);
            }
        };
        $walk($id);

        return $out;
    }

    /** Whether $inner is $outer or hangs from it. */
    public function inside(int $inner, int $outer): bool
    {
        for ($at = $inner, $guard = 0; $at !== 0 && $guard++ < 100000; $at = $this->block($at)?->parent ?? 0) {
            if ($at === $outer) {
                return true;
            }
        }

        return false;
    }

    /** The last step of the chain that starts at $id (following Next). */
    public function lastOfChain(int $id): int
    {
        for ($b = $this->block($id), $guard = 0; $b !== null && $b->next !== 0 && $guard++ < 100000; $b = $this->block($b->next)) {
        }

        return $b?->id ?? 0;
    }

    /** The top of the script $id belongs to. */
    public function topOf(int $id): int
    {
        for ($b = $this->block($id), $guard = 0; $b !== null && $b->parent !== 0 && $guard++ < 100000; $b = $this->block($b->parent)) {
        }

        return $b?->id ?? 0;
    }

    /** @return list<int> the tops of the scripts, back to front */
    public function scripts(): array
    {
        return $this->order;
    }

    /** Puts a script in front of the others. */
    public function raise(int $id): void
    {
        if (($at = array_search($id, $this->order, true)) !== false) {
            array_splice($this->order, $at, 1);
            $this->order[] = $id;
        }
    }

    /** Takes a block (with what is under it) out of its place: a script of its own at x, y. */
    public function detach(int $id, ?float $x = null, ?float $y = null): void
    {
        if (($b = $this->block($id)) === null) {
            return;
        }
        $this->makeTop($id, $x ?? $b->lx, $y ?? $b->ly);
    }

    private function makeTop(int $id, float $x, float $y): void
    {
        if (($b = $this->block($id)) === null) {
            return;
        }
        $this->unhook($b);
        $b->x = $x;
        $b->y = $y;
        $this->order[] = $id;
        $this->revision++;
    }

    /** Takes it out of whatever holds it (or out of the list of scripts). */
    private function unhook(Block $b): void
    {
        if ($b->parent === 0) {
            $this->order = array_values(array_diff($this->order, [$b->id]));

            return;
        }
        if (($p = $this->block($b->parent)) !== null) {
            match ($b->place) {
                BlockPlace::Next => $p->next === $b->id && $p->next = 0,
                BlockPlace::Body => ($p->body[$b->index] ?? 0) === $b->id && $p->body[$b->index] = 0,
                BlockPlace::Input => ($p->input[$b->index] ?? 0) === $b->id && $p->input[$b->index] = 0,
            };
        }
        $b->parent = 0;
        $b->place = BlockPlace::Next;
        $b->index = 0;
    }

    /**
     * Whether a block may go at a place of another one: a value only into a slot that takes its shape (a number or text
     * slot a reporter, a condition slot a boolean, a free slot either, a menu slot none); a step under a step that has a
     * next, or in a mouth; never a hat anywhere but the top, never into a block it carries, never into the palette.
     */
    public function canAttach(int $id, int $target, BlockPlace $place, int $index = 0): bool
    {
        if (($b = $this->block($id)) === null || ($t = $this->block($target)) === null || $this->inside($target, $id)) {
            return false;
        }
        $kb = $this->kind($b->kind);
        $kt = $this->kind($t->kind);
        if ($kb === null || $kt === null) {
            return false;
        }
        if ($place === BlockPlace::Input) {
            return $kb->shape->isValue() && $this->slotTakes($kt, $index, $kb->shape);
        }
        if ($kb->shape->isValue() || $kb->shape === BlockShape::Hat || $kt->shape->isValue()) {
            return false;
        }

        return match ($place) {
            BlockPlace::Next => $kt->shape->hasNext(),
            BlockPlace::Body => $kt->shape === BlockShape::C && $index >= 0 && $index < $kt->sections,
            default => false,
        };
    }

    /** Whether slot $index of a kind takes a value of this shape. */
    public function slotTakes(BlockKind $kind, int $index, BlockShape $shape): bool
    {
        if ($index < 0 || $index >= $kind->slotCount) {
            return false;
        }

        return match ($kind->slotTypes[$index]) {
            'm' => false,
            'b' => $shape === BlockShape::Boolean,
            'n', 's' => $shape === BlockShape::Reporter,
            default => $shape->isValue(),
        };
    }

    /**
     * Puts a block (with what is under it) at a place of another one; false when it does not fit there. A value that was
     * in the slot jumps out beside; a lone C block dropped in a chain wraps what was under that place; a stack that ends in
     * a cap leaves what was under the place beside.
     */
    public function attach(int $id, int $target, BlockPlace $place, int $index = 0): bool
    {
        if (! $this->canAttach($id, $target, $place, $index)) {
            return false;
        }
        $b = $this->blocks[$id];
        $t = $this->blocks[$target];
        $this->unhook($b);
        if ($place === BlockPlace::Input) {
            if (($old = $t->input[$index] ?? 0) !== 0) {
                $this->makeTop($old, $t->lx + $t->w + 20.0, $t->ly);
            }
            $t->input[$index] = $id;
        } else {
            $after = $place === BlockPlace::Next ? $t->next : ($t->body[$index] ?? 0);
            $last = $this->blocks[$this->lastOfChain($id)];
            if ($after !== 0 && ($rest = $this->block($after)) !== null) {
                $kl = $this->kind($last->kind);
                if ($kl->shape === BlockShape::C && $last === $b && ($b->body[0] ?? 0) === 0) {
                    // a lone C block wraps what was under it
                    $b->body[0] = $after;
                    $rest->parent = $b->id;
                    $rest->place = BlockPlace::Body;
                    $rest->index = 0;
                } elseif ($kl->shape->hasNext()) {
                    $last->next = $after;
                    $rest->parent = $last->id;
                    $rest->place = BlockPlace::Next;
                    $rest->index = 0;
                } else {
                    // the stack ends in a cap: what was under goes beside
                    $rest->parent = 0;
                    $rest->x = $t->lx + $t->w + 30.0;
                    $rest->y = $t->ly + $t->h;
                    $this->order[] = $rest->id;
                }
            }
            $place === BlockPlace::Next ? $t->next = $id : $t->body[$index] = $id;
        }
        $b->parent = $target;
        $b->place = $place;
        $b->index = $index;
        $this->revision++;

        return true;
    }

    /** A copy of a block with what is under it and in it, as a script of its own at x, y. @return int the copy (0: there is no such block) */
    public function duplicate(int $id, float $x, float $y): int
    {
        if ($this->block($id) === null) {
            return 0;
        }
        $first = $this->loadChain($this->saveChain($id), $x, $y);
        $this->order[] = $first;
        $this->revision++;

        return $first;
    }

    // ---------------------------------------------------------------- values, glow, selection

    public function value(int $id, int $slot): ?string
    {
        return $this->block($id)?->values[$slot] ?? null;
    }

    /** A number slot keeps a number. */
    public function setValue(int $id, int $slot, string $value): bool
    {
        if (($b = $this->block($id)) === null || ($k = $this->kind($b->kind)) === null || $slot < 0 || $slot >= $k->slotCount) {
            return false;
        }
        if ($k->slotTypes[$slot] === 'n') {
            $value = self::number($value);
        }
        $b->values[$slot] = $value;
        $this->revision++;

        return true;
    }

    /** The number a text reads as, written plainly ("12abc" is 12, "x" is 0, "1.50" is 1.5). */
    public static function number(string $text): string
    {
        preg_match('/^\s*[-+]?(\d+(\.\d*)?|\.\d+)([eE][-+]?\d+)?/', $text, $m);

        return isset($m[0]) ? (string) (float) $m[0] : '0';
    }

    public function setGlow(int $id, bool $glow): void
    {
        ($b = $this->block($id)) !== null && $b->glow = $glow;
    }

    public function setLocked(int $id, bool $locked): void
    {
        ($b = $this->block($id)) !== null && $b->locked = $locked;
    }

    /** @return list<int> */
    public function selected(): array
    {
        return array_values(array_map(fn (Block $b) => $b->id, array_filter($this->blocks, fn (Block $b) => $b->selected)));
    }

    /** Selects a block (add: false clears the selection first; block 0 only clears). */
    public function select(int $id, bool $add = false): void
    {
        $add || $this->clearSelection();
        ($b = $this->block($id)) !== null && $b->selected = true;
    }

    public function clearSelection(): void
    {
        foreach ($this->blocks as $b) {
            $b->selected = false;
        }
    }

    public function revision(): int
    {
        return $this->revision;
    }

    // ---------------------------------------------------------------- save and load

    /** Every script as text (JSON): kinds by name, values by slot, nesting as the blocks hang. */
    public function save(): string
    {
        $scripts = [];
        foreach ($this->order as $top) {
            $b = $this->blocks[$top];
            $scripts[] = ['x' => $b->x, 'y' => $b->y, 'blocks' => $this->saveChain($top)];
        }

        return json_encode(['scripts' => $scripts], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return list<array<string, mixed>> */
    private function saveChain(int $first): array
    {
        $out = [];
        for ($b = $this->block($first), $guard = 0; $b !== null && $guard++ < 100000; $b = $this->block($b->next)) {
            $entry = ['kind' => $b->kind, 'values' => array_values($b->values)];
            foreach ($b->input as $slot => $value) {
                $value !== 0 && $entry['inputs'][$slot] = $this->saveChain($value)[0];
            }
            foreach ($b->body as $mouth => $inner) {
                $inner !== 0 && $entry['bodies'][$mouth] = $this->saveChain($inner);
            }
            $out[] = $entry;
        }

        return $out;
    }

    /** Replaces the scripts with those of a text from save(). False, and nothing changes, when the text is not one or names an unknown kind. */
    public function load(string $text): bool
    {
        $data = json_decode($text, true);
        if (! is_array($data) || ! is_array($data['scripts'] ?? null)) {
            return false;
        }
        $was = [$this->blocks, $this->order, $this->nextId];
        $this->blocks = $this->order = [];
        try {
            foreach ($data['scripts'] as $script) {
                $chain = $script['blocks'] ?? null;
                if (! is_array($chain) || $chain === []) {
                    throw new InvalidArgumentException('A script without blocks.');
                }
                $first = $this->loadChain($chain, (float) ($script['x'] ?? 0), (float) ($script['y'] ?? 0));
                $this->order[] = $first;
            }
        } catch (InvalidArgumentException) {
            [$this->blocks, $this->order, $this->nextId] = $was;

            return false;
        }
        $this->revision++;

        return true;
    }

    /** @param list<array<string, mixed>> $chain */
    private function loadChain(array $chain, float $x = 0.0, float $y = 0.0, int $parent = 0, BlockPlace $place = BlockPlace::Next, int $index = 0): int
    {
        $first = $previous = 0;
        foreach ($chain as $entry) {
            $k = is_array($entry) ? $this->kind((string) ($entry['kind'] ?? '')) : null;
            if ($k === null) {
                throw new InvalidArgumentException('Unknown kind.');
            }
            $b = new Block($this->nextId++, $k->name);
            $this->blocks[$b->id] = $b;
            $b->x = $x;
            $b->y = $y;
            $b->values = $k->defaults;
            foreach ((array) ($entry['values'] ?? []) as $slot => $value) {
                $slot < $k->slotCount && $b->values[$slot] = (string) $value;
            }
            if ($previous === 0) {
                $first = $b->id;
                $b->parent = $parent;
                $b->place = $place;
                $b->index = $index;
            } else {
                $b->parent = $previous;
                $b->place = BlockPlace::Next;
                $this->blocks[$previous]->next = $b->id;
            }
            foreach ((array) ($entry['inputs'] ?? []) as $slot => $inner) {
                if (! $this->slotTakes($k, (int) $slot, ($ik = $this->kind((string) ($inner['kind'] ?? '')))?->shape ?? BlockShape::Statement)) {
                    throw new InvalidArgumentException('A value that does not fit its slot.');
                }
                $b->input[(int) $slot] = $this->loadChain([$inner], parent: $b->id, place: BlockPlace::Input, index: (int) $slot);
            }
            foreach ((array) ($entry['bodies'] ?? []) as $mouth => $inner) {
                if ($k->shape !== BlockShape::C || (int) $mouth >= $k->sections || ! is_array($inner) || $inner === []) {
                    throw new InvalidArgumentException('A mouth that is not there.');
                }
                $b->body[(int) $mouth] = $this->loadChain($inner, parent: $b->id, place: BlockPlace::Body, index: (int) $mouth);
            }
            $previous = $b->id;
        }

        return $first;
    }
}
