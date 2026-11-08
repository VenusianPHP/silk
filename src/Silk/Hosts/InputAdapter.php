<?php

namespace Silk\Hosts;

use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Surface\Contracts\Windows\Mail\View\ScrollPhase;

/**
 * Pointer and key mail to SilkEvent. Canvas and staged mail share their fields, so one mapping
 * serves both; a subclass names the family it takes. Points become logical units by the UI scale.
 */
abstract class InputAdapter
{
    /** Wheel points per line, for precise (trackpad) deltas. */
    public const float WHEEL_LINE = 48.0;

    private float $x = 0.0;

    private float $y = 0.0;

    public function __construct(private float $scale = 1.0) {}

    public function setScale(float $scale): void
    {
        $this->scale = $scale;
    }

    /** The class-name prefix of the family: 'Canvas' or 'Staged'. */
    abstract protected function takes(object $mail): ?string;

    public function map(object $mail): ?SilkEvent
    {
        $verb = $this->takes($mail);
        if ($verb === null) {
            return null;
        }
        $f = fn (string $field) => $mail->{$field} ?? null;
        $at = fn () => isset($mail->x) ? [$this->x = $mail->x / $this->scale, $this->y = $mail->y / $this->scale] : [$this->x, $this->y];

        return match ($verb) {
            'Pressed' => new SilkEvent(EventType::MouseDown, ...$at(), button: $mail->button, clicks: $mail->clicks, modifiers: $mail->modifiers),
            'Released' => new SilkEvent(EventType::MouseUp, ...$at(), button: $mail->button, clicks: 1, modifiers: $mail->modifiers),
            'Dragged' => new SilkEvent(EventType::MouseMove, ...$at(), button: $mail->button, modifiers: $mail->modifiers),
            'Moved' => new SilkEvent(EventType::MouseMove, ...$at(), modifiers: $mail->modifiers),
            'Wheeled' => new SilkEvent(EventType::Wheel, ...$at(), modifiers: $mail->modifiers,
                wheelX: $mail->precise ? $mail->dx / self::WHEEL_LINE : (float) $mail->dx,
                wheelY: $mail->precise ? $mail->dy / self::WHEEL_LINE : (float) $mail->dy,
                precise: $mail->precise, phase: $f('phase') ?? ScrollPhase::NONE),
            'Entered' => new SilkEvent(EventType::Enter, ...$at()),
            'Left' => new SilkEvent(EventType::Leave, ...$at()),
            'KeyPressed' => new SilkEvent(EventType::KeyDown, key: $mail->key, modifiers: $mail->modifiers, repeat: $mail->repeat,
                text: $mail->character === '' ? null : $mail->character),
            'KeyReleased' => new SilkEvent(EventType::KeyUp, key: $mail->key, modifiers: $mail->modifiers),
            'TextCommitted' => new SilkEvent(EventType::Text, text: $mail->text),
            'EditCommanded' => new SilkEvent(EventType::Edit, command: $mail->command),
            'FocusGained' => new SilkEvent(EventType::FocusGained),
            'FocusLost' => new SilkEvent(EventType::FocusLost),
            default => null,
        };
    }

    /** The class's short name without $prefix, if it starts with it. */
    protected static function verb(object $mail, string $namespace, string $prefix = ''): ?string
    {
        $class = $mail::class;
        if (! str_starts_with($class, $namespace.'\\')) {
            return null;
        }
        $short = substr($class, strlen($namespace) + 1);

        return str_starts_with($short, $prefix) ? substr($short, strlen($prefix)) : $short;
    }
}
