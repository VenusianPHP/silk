<?php

namespace Silk\Contracts\Input;

use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Input\EditCommand;
use Surface\Contracts\Windows\Mail\View\ScrollPhase;

/** One input event, shaped like Raven's REvent. x/y are logical units; wheel deltas are lines. */
readonly class SilkEvent
{
    public Modifiers $modifiers;

    public function __construct(
        public EventType $type,
        public float $x = 0.0,
        public float $y = 0.0,
        public ?MouseButton $button = null,
        public int $clicks = 0,
        public ?Key $key = null,
        ?Modifiers $modifiers = null,
        public float $wheelX = 0.0,
        public float $wheelY = 0.0,
        public bool $precise = false,
        public ?ScrollPhase $phase = null,
        public ?string $text = null,
        public bool $repeat = false,
        public ?EditCommand $command = null,
        /** FocusGained: the focus came from the keyboard and shows its ring (Raven focus_visible). */
        public bool $focusVisible = false,
    ) {
        $this->modifiers = $modifiers ?? new Modifiers;
    }

    /** The same event at a point moved by $dx, $dy (into a scrolled child's coordinates). */
    public function moved(float $dx, float $dy): self
    {
        if ($dx == 0.0 && $dy == 0.0) {
            return $this;
        }
        return new self($this->type, $this->x + $dx, $this->y + $dy, $this->button, $this->clicks, $this->key, $this->modifiers,
            $this->wheelX, $this->wheelY, $this->precise, $this->phase, $this->text, $this->repeat, $this->command);
    }

    /** The same event with another type, for Enter/Leave and focus mail. */
    public function as(EventType $type): self
    {
        return new self($type, $this->x, $this->y, $this->button, $this->clicks, $this->key, $this->modifiers,
            $this->wheelX, $this->wheelY, $this->precise, $this->phase, $this->text, $this->repeat, $this->command);
    }
}
