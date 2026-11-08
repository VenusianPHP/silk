<?php

namespace Silk\Widgets\Blocks;

/**
 * A kind of block: its name, label, shape and category. The label is words and slots: `%n` a number, `%s` a text,
 * `%b` a condition, `%r` any value, `%m` a choice in a menu; `{text}` right after a slot is its default value; `|`
 * separates the sections of a C block, each followed by a mouth: "repeat %n{10}", "if %b then|else", "%n + %n".
 */
final class BlockKind
{
    public const int MAX_SLOTS = 12;

    public const int MAX_MOUTHS = 6;

    /** @var list<array{section: int, slot: int, text: ?string}> in reading order; slot -1 is a word */
    public array $tokens = [];

    public int $sections = 1;

    public int $slotCount = 0;

    /** @var list<string> n, s, b, r or m, by slot */
    public array $slotTypes = [];

    /** @var list<string> */
    public array $defaults = [];

    /** @var array<int, list<string>> the choices of a %m slot; none: it is typed in */
    public array $options = [];

    /** Not in the palette (the blocks of that kind stay where they are). */
    public bool $hidden = false;

    public function __construct(
        public readonly string $name,
        public string $label,
        public readonly BlockShape $shape,
        public readonly string $category = '',
    ) {
        $this->parse();
    }

    /** Reads the label again (after a new one was given). */
    public function parse(): void
    {
        $this->tokens = $this->slotTypes = $this->defaults = [];
        $this->slotCount = 0;
        $section = 0;
        $word = '';
        $n = strlen($this->label);
        $flush = function () use (&$word, &$section): void {
            $word !== '' && $this->tokens[] = ['section' => $section, 'slot' => -1, 'text' => $word];
            $word = '';
        };
        for ($i = 0; $i < $n; $i++) {
            $c = $this->label[$i];
            if ($c === '|') {
                $flush();
                $section++;

                continue;
            }
            if ($c === '%' && $i + 1 < $n && str_contains('nsbrm', $this->label[$i + 1]) && $this->slotCount < self::MAX_SLOTS) {
                $flush();
                $slot = $this->slotCount++;
                $this->slotTypes[$slot] = $this->label[$i + 1];
                $i++;
                $default = null;
                if ($i + 1 < $n && $this->label[$i + 1] === '{' && ($end = strpos($this->label, '}', $i + 1)) !== false) {
                    $default = substr($this->label, $i + 2, $end - $i - 2);
                    $i = $end;
                }
                $this->defaults[$slot] = $default ?? ($this->slotTypes[$slot] === 'n' ? '0' : '');
                $this->tokens[] = ['section' => $section, 'slot' => $slot, 'text' => null];

                continue;
            }
            $word .= $c;
        }
        $flush();
        $this->sections = $this->shape === BlockShape::C ? min($section + 1, self::MAX_MOUTHS) : 1;
    }
}
