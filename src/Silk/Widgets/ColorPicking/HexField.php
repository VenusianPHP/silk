<?php

namespace Silk\Widgets\ColorPicking;

use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Widgets\ColorPicker;
use Silk\Widgets\TextInput;
use Surface\Contracts\HumanInput\Key;

/** A ColorPicker's code field: read as it is typed, written back as a proper code on Enter or focus loss (color_picker.c:270-294). */
final class HexField extends TextInput
{
    public function __construct(string $name, private readonly ColorPicker $picker)
    {
        parent::__construct($name);
        $this->setTooltip('Code of the color: #RRGGBB or #RRGGBBAA');
    }

    protected function textEdited(): void
    {
        $this->picker->hexTyped($this, $this->value());
    }

    public function handle(SilkEvent $e): bool
    {
        if ($e->type === EventType::KeyDown && ($e->key === Key::ENTER || $e->key === Key::NUMPAD_ENTER)) {
            $this->picker->syncFields();

            return true;
        }
        $e->type === EventType::FocusLost && $this->picker->syncFields();

        return parent::handle($e);
    }
}
