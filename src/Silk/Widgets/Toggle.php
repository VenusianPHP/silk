<?php

namespace Silk\Widgets;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\PartLook;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\State;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Silk\Core\Tree\Widget;
use Silk\Painter\Knob;
use Silk\Painter\PartBox;
use Silk\Widgets\Concerns\EasesParts;
use Silk\Widgets\Concerns\Hoverable;
use Silk\Widgets\Concerns\Pressable;
use Surface\Contracts\Windows\Mail\View\Toggled;
use Surface\NutsAndBolts\Color;

/** The switch: `toggle(name, on)` as TKToggle; posts Toggled. The knob rides a BOUNCY spring. Raven widgets/checkbox.c (R_CHECKBOX_SWITCH). */
class Toggle extends Widget
{
    use EasesParts, Hoverable, Pressable;

    private bool $hovered = false;

    private Spring $knob;

    public function __construct(string $name, private bool $on = false)
    {
        parent::__construct($name);
        $this->focusable = true;
        // as Raven's spring, it starts at 0: a switch made on slides there on its first frames
        $this->knob = (new Spring(0.0, SpringPreset::Bouncy))->target($on ? 1.0 : 0.0);
    }

    public function isOn(): bool
    {
        return $this->on;
    }

    /** Programmatic: posts nothing; the knob slides there. */
    public function setOn(bool $on): static
    {
        $this->on = $on;
        // Raven's spring runs to the state however it changes (checkbox.c:305)
        $this->knob->target($on ? 1.0 : 0.0);

        return $this->invalidatePaint();
    }

    public function knob(): Spring
    {
        return $this->knob;
    }

    /** The switch: floor(CheckSize x 1.9) by CheckSize, or the family's size (checkbox.c:29-41); a row high at least (56). */
    private function mark(): array
    {
        $look = $this->look();
        $style = $this->style();
        $size = $look->metric(Metric::CheckSize);
        // the switch of the glass themes is a long capsule with an elongated knob (checkbox.c:33-34)
        if ($look->material()->isGlass()) {
            return [floor(($size + 6) * 2.1), $size + 6];
        }
        // macOS 26: a little longer, for its elongated knob (checkbox.c:38)
        if ($look->liquid()) {
            return [42.0, 22.0];
        }

        return [(float) ($style['width'] ?? floor($size * 1.9)), (float) ($style['height'] ?? $size)];
    }

    /** @return array<string, float|string> */
    private function style(): array
    {
        return $this->look()->part('Toggle', 'Body', State::Normal)->extra;
    }

    protected function measureContent(Size $available): Size
    {
        [$w, $h] = $this->mark();

        return new Size($w, max($h, $this->look()->metric(Metric::RowHeight)));
    }

    public function springs(): array
    {
        return [$this->hover(), $this->press(), $this->knob];
    }

    public function handle(SilkEvent $e): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }
        if ($e->type === EventType::Enter || $e->type === EventType::Leave) {
            $this->hovered = $e->type === EventType::Enter;
        }
        $this->hoverEvent($e);

        return $this->pressEvent($e);
    }

    protected function activate(): void
    {
        $this->on = ! $this->on;
        $this->knob->target($this->on ? 1.0 : 0.0);
        $this->invalidatePaint();
        $this->post(new Toggled($this->window(), $this->path(), $this->uuid(), $this->on));
    }

    public function paint(Painter $p): void
    {
        $b = $this->bounds();
        [$w, $h] = $this->mark();
        $box = new Rect($b->x, $b->y + floor(($b->h - $h) / 2), $w, $h);
        ($this->style()['paint'] ?? 'raven') === 'parts' ? $this->paintParts($p, $box) : $this->paintRaven($p, $box);
    }

    /** Raven's own: the knob slides, stretched by its speed and by the finger, and bounces on arrival (checkbox.c:319-340). */
    private function paintRaven(Painter $p, Rect $box): void
    {
        $look = $this->look();
        $on = $this->knob->value();
        $mix = max(0.0, min(1.0, $on));
        $hovered = $this->hovered && $this->isEnabled();
        $accent = $look->color($hovered ? Role::AccentHovered : Role::Accent);
        $off = $look->color($this->isPressed() ? Role::ControlPressed : ($hovered ? Role::ControlHovered : Role::Control));
        $outline = $look->color(Role::ControlBorder);
        if (! $this->isEnabled()) {
            [$accent, $off, $outline] = [self::fade($accent, 0.4), self::fade($off, 0.5), self::fade($outline, 0.5)];
        }
        $glass = $look->material()->isGlass();
        $knobH = $box->h - 4;
        // glass: a longer knob, on a Track that turns Switch (checkbox.c:320-327)
        $knobW0 = $glass ? floor($knobH * 1.45) : $knobH;
        $held = max(0.0, min(1.0, $this->press()->value()));
        $stretch = min(abs($this->knob->velocity()) * 0.035, 0.45) * $knobH + $held * $knobH * 0.28;
        $knobW = $knobW0 + $stretch;
        $x = $box->x + 2 + ($box->w - $knobW0 - 4) * max(-0.06, min(1.06, $on)) - $stretch * $mix;
        $x = max($box->x + 0.5, min($box->x + $box->w - 2 - $knobW + 1.5, $x));
        $track = self::mix($glass ? $look->color(Role::Track) : $off, $glass ? $look->color(Role::Switch) : $accent, $mix);
        $this->isEnabled() || $track = self::fade($track, 0.5);
        PartBox::paint($p, $box, $track, $box->h / 2, $outline, $glass ? 0.0 : $look->metric(Metric::BorderWidth) * (1 - $mix));
        if ($glass) {
            Knob::paint($p, $look, new Rect($x, $box->y + 2, $knobW, $knobH), $held, $this->isEnabled());

            return;
        }
        $p->rect(new Rect($x, $box->y + 2, $knobW, $knobH), self::mix($look->color(Role::HandleHovered), $look->color(Role::AccentText), $mix), $knobH / 2);
    }

    /** The platforms': Track and Knob parts, off and on ('on' class) mixed by the spring (checkbox.c:155-180, 198-228). */
    private function paintParts(Painter $p, Rect $box): void
    {
        $mix = max(0.0, min(1.0, $this->knob->value()));
        [$hover, $press] = [$this->hover()->value(), $this->press()->value()];
        $track = self::mixParts($this->easedPart('Toggle', 'Track', [], $hover, $press), $this->easedPart('Toggle', 'Track', ['on'], $hover, $press), $mix);
        $this->part($p, $box, $track);
        $knob = self::mixParts($this->easedPart('Toggle', 'Knob', [], $hover, $press), $this->easedPart('Toggle', 'Knob', ['on'], $hover, $press), $mix);
        if ($this->look()->liquid()) {
            // macOS 26: a white capsule, wider than high, that turns into a lens of clear glass while it is held (checkbox.c:209-220)
            $knobH = $box->h - 4;
            $knobW = floor($knobH * 1.4);
            $p->pushOpacity((float) ($knob->extra['opacity'] ?? 1.0));
            Knob::paint($p, $this->look(), new Rect($box->x + 2 + ($box->w - $knobW - 4) * $mix, $box->y + 2, $knobW, $knobH), max(0.0, min(1.0, $press)), $this->isEnabled());
            $p->popOpacity();

            return;
        }
        $size = $box->h - (float) ($knob->extra['inset'] ?? 2.0);
        // pressed, the knob stretches toward where it would go; GNOME's does not (stretch 0)
        $width = $size + ($box->h * 0.85 - $size) * max(0.0, min(1.0, $press)) * (float) ($this->style()['stretch'] ?? 1.0);
        $centre = $box->x + $box->h / 2 + ($box->w - $box->h) * $mix + ($this->on ? -1 : 1) * ($width - $size) / 2;
        $this->part($p, new Rect($centre - $width / 2, $box->y + ($box->h - $size) / 2, $width, $size), $knob);
    }

    /** rvn_paint_part: opacity, a shadow blur/4 down (the engine's offset), the box; round as the rect allows (look.c:363-374). */
    private function part(Painter $p, Rect $r, PartLook $l): void
    {
        $radius = min($l->radius, min($r->w, $r->h) / 2);
        $p->pushOpacity((float) ($l->extra['opacity'] ?? 1.0));
        if ($l->shadow > 0 && $l->shadowColor !== null && $l->shadowColor->alpha > 0) {
            $p->shadow($r, $radius, $l->shadow, $l->shadowColor);
        }
        PartBox::paint($p, $r, $l->background, $radius, $l->border, $l->borderWidth, $l->borderTop, $l->borderBottom);
        $p->popOpacity();
    }

    private static function fade(Color $c, float $by): Color
    {
        return new Color($c->red, $c->green, $c->blue, $c->alpha * $by);
    }
}
