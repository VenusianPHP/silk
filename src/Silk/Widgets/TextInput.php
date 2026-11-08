<?php

namespace Silk\Widgets;

use Silk\Painter\Surfaces;
use Silk\Contracts\Look\SurfaceKind;
use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\State;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Silk\Core\Tree\Widget;
use Silk\Widgets\Concerns\EditsText;
use Silk\Widgets\Concerns\Hoverable;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\Windows\Mail\View\TextChanged;
use Surface\Contracts\Windows\Mail\View\TextSubmitted;
use Surface\NutsAndBolts\Color;
use Silk\Painter\PartBox;

/**
 * `textInput(name, value, placeholder, secret)` as TKTextInput: one line of editable text. Edits come from
 * committed text (Text events) and the platform's editing bindings (Edit events); Enter posts TextSubmitted.
 * Every user edit posts TextChanged; setValue() posts nothing. The text is reshaped only when it changes.
 */
class TextInput extends Widget
{
    use EditsText, Hoverable;

    /** Raven's natural width. */
    public const float WIDTH = 140.0;

    /** The focus ring closing in (BOUNCY). */
    private Spring $focusSpring;

    public function __construct(
        string $name,
        string $value = '',
        private ?string $placeholder = null,
        private readonly bool $secret = false,
    ) {
        parent::__construct($name);
        $this->focusable = true;
        $this->initEditing($value);
        $this->focusSpring = new Spring(0.0, SpringPreset::Bouncy);
    }

    public function value(): string
    {
        return $this->buffer->text();
    }

    /** Posts nothing: the app changed it, it knows. */
    public function setValue(string $value): static
    {
        $this->replaceText($value);

        return $this;
    }

    public function placeholder(): ?string
    {
        return $this->placeholder;
    }

    public function setPlaceholder(?string $placeholder): static
    {
        $this->placeholder = $placeholder;

        return $this->invalidatePaint();
    }

    public function isSecret(): bool
    {
        return $this->secret;
    }

    public function setTextColor(?Color $color): static
    {
        return $this->setColor(Role::Text, $color);
    }

    public function springs(): array
    {
        return [$this->hover(), $this->focusSpring, ...$this->editingSprings()];
    }

    protected function measureContent(Size $available): Size
    {
        return new Size(self::WIDTH, $this->look()->metric(Metric::ControlHeight));
    }

    public function handle(SilkEvent $e): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }
        $this->hoverEvent($e);
        if ($e->type === EventType::FocusGained || $e->type === EventType::FocusLost) {
            $this->focusSpring->target($e->type === EventType::FocusGained ? 1.0 : 0.0);
        }
        if ($e->type === EventType::KeyDown) {
            return $this->key($e);
        }

        return $this->editEvent($e) ?? false;
    }

    public function paint(Painter $p): void
    {
        $look = $this->look();
        $b = $this->bounds();
        $body = $look->part('TextInput', 'Body', $this->isEnabled() ? State::Normal : State::Disabled);
        $focused = $look->part('TextInput', 'Body', State::Focused);
        $lit = max(0.0, min(1.0, $this->focusSpring->value()));
        $radius = $body->radius;

        // the border eases to the focused one (Raven rvn_part_look_eased); its width too, for a ring that grows in
        $fill = $body->background;
        $width = $body->borderWidth + ($focused->borderWidth - $body->borderWidth) * $lit;
        if ($look->material()->isGlass()) {
            // text_input.c:66-73: a field of darker glass; the focus is a ring of the accent that closes in
            $r = $b->h / 2;
            Surfaces::paint($p, $look, $b, $r, SurfaceKind::Input, 0.0, 0.0, $this->isEnabled());
            if ($lit > 0.01) {
                $away = (1 - $this->focusSpring->value()) * 5;
                $f = $this->color(Role::Focus, $look);
                $p->rect(new Rect($b->x - $away, $b->y - $away, $b->w + 2 * $away, $b->h + 2 * $away), new Color(0, 0, 0, 0), $r + $away,
                    new Color($f->red, $f->green, $f->blue, $f->alpha * $lit), 1.5);
            }
        } else {
            PartBox::paint($p, $b, $fill, $radius, self::mix($body->border, $focused->border, $lit), $width, $body->borderTop, $body->borderBottom);
        }
        if (! $look->material()->isGlass() && ($body->extra['focusCloses'] ?? 1.0) > 0 && $lit > 0.01 && $lit < 0.99 && $focused->border !== null) {
            // Raven: the focus ring closes in on the field instead of just appearing
            $away = (1 - $this->focusSpring->value()) * 4;
            $ring = $focused->border;
            $p->rect(new Rect($b->x - $away, $b->y - $away, $b->w + 2 * $away, $b->h + 2 * $away), new Color(0, 0, 0, 0),
                $radius + $away, new Color($ring->red, $ring->green, $ring->blue, $ring->alpha * $lit * (1 - $lit) * 2), 1.0);
        }
        // a ring around the field, as wide as its style says, in its colour faded by the focus (Raven platform_looks.c:317-321)
        $ring = $look->part('TextInput', 'Ring', State::Focused);
        if ($ring->borderWidth > 0 && $ring->border !== null && $lit > 0.01) {
            $w = $ring->borderWidth;
            $p->rect(new Rect($b->x - $w, $b->y - $w, $b->w + 2 * $w, $b->h + 2 * $w), new Color(0, 0, 0, 0), $radius + $w,
                new Color($ring->border->red, $ring->border->green, $ring->border->blue, $ring->border->alpha * $lit), $w);
        }

        if ($this->buffer->text() === '' && $this->placeholder !== null && ! $this->hasFocus) {
            $area = $this->textArea();
            $p->pushClip($area);
            $p->text($this->placeholder, $area->x, $this->baselineIn($area, $this->font()), $look->color(Role::TextPlaceholder), $this->font());
            $p->popClip();

            return;
        }
        $this->paintEditing($p, $body->text ?? $this->color(Role::Text, $look));
    }

    protected function textArea(): Rect
    {
        $b = $this->bounds();
        $px = $this->look()->part('TextInput', 'Body', State::Normal)->extra['textInset'] ?? $this->look()->metric(Metric::PaddingX) * 0.7;

        return new Rect($b->x + $px, $b->y, max(0.0, $b->w - 2 * $px), $b->h);
    }

    protected function textEdited(): void
    {
        $this->post(new TextChanged($this->window(), $this->path(), $this->uuid(), $this->value()));
    }

    protected function secretText(): bool
    {
        return $this->secret;
    }

    private function key(SilkEvent $e): bool
    {
        if ($e->key === Key::ENTER && ! $e->repeat) {
            $this->post(new TextSubmitted($this->window(), $this->path(), $this->uuid(), $this->value()));

            return true;
        }

        // the key's edit arrives as an Edit or Text event; keep the key from bubbling, except Escape
        return $e->key !== Key::ESCAPE;
    }
}
