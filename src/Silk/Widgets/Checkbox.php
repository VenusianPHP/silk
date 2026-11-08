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
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Silk\Painter\Icons;
use Silk\Painter\PartBox;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Tree\Widget;
use Silk\Widgets\Concerns\EasesParts;
use Silk\Widgets\Concerns\Hoverable;
use Silk\Widgets\Concerns\MeasuresText;
use Silk\Widgets\Concerns\Pressable;
use Surface\Contracts\Windows\Mail\View\Toggled;
use Surface\NutsAndBolts\Color;

/** `checkbox(name, label, checked)` as TKCheckbox; posts Toggled on a click or Space. Raven widgets/checkbox.c (R_CHECKBOX_CHECK). */
class Checkbox extends Widget
{
    use EasesParts, Hoverable, MeasuresText, Pressable;

    private ?Spring $on = null;

    private bool $hovered = false;

    public function __construct(string $name, private string $label, private bool $checked = false)
    {
        parent::__construct($name);
        $this->focusable = true;
        // as Raven's spring, it starts at 0: a checkbox made checked pops in on its first frames
        $this->on()->target($checked ? 1.0 : 0.0);
    }

    public function label(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this->invalidateLayout();
    }

    public function isChecked(): bool
    {
        return $this->checked;
    }

    public function setChecked(bool $checked): static
    {
        $this->clearedRadios = [];
        if ($checked && $this->isRadio()) {
            foreach ($this->parent()?->children() ?? [] as $sibling) {
                if ($sibling !== $this && $sibling instanceof self && $sibling->isRadio() && $sibling->checked) {
                    $this->clearedRadios[] = $sibling->setChecked(false);
                }
            }
        }
        $this->checked = $checked;
        $this->on()->target($checked ? 1.0 : 0.0);

        return $this->invalidatePaint();
    }

    /** The mark: CheckSize square, grown by the style (GNOME: 14 inside 20; checkbox.c:42-44). */
    private function mark(): float
    {
        return $this->look()->metric(Metric::CheckSize) + (float) ($this->style()['markGrow'] ?? 0.0);
    }

    /** @return array<string, float|string> */
    private function style(): array
    {
        return $this->look()->part('Checkbox', 'Body', State::Normal)->extra;
    }

    /** The mark, then the text after Spacing; at least a row high (checkbox.c:47-58). */
    protected function measureContent(Size $available): Size
    {
        $look = $this->look();
        [$w, $h] = [$this->mark(), $this->mark()];
        if ($this->label !== '') {
            $w += $look->metric(Metric::Spacing) + ceil($this->textSize($this->label)->w);
            $h = max($h, ceil($this->textSize($this->label)->h));
        }

        return new Size($w, max($h, $look->metric(Metric::RowHeight)));
    }

    public function springs(): array
    {
        return [$this->hover(), $this->press(), $this->on()];
    }

    /** 0 off .. 1 on, BOUNCY: it runs past its end, which makes the mark pop (checkbox.c:304-305). */
    public function on(): Spring
    {
        return $this->on ??= new Spring(0.0, SpringPreset::Bouncy);
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

    /** A radio button: round, a dot; the radios of one parent exclude each other (checkbox.c:383-393). */
    public function isRadio(): bool
    {
        return false;
    }

    protected function activate(): void
    {
        // a radio is unchecked only by checking another (checkbox.c:400-405)
        if ($this->isRadio() && $this->checked) {
            return;
        }
        $off = $this->setChecked(! $this->checked)->clearedRadios;
        $this->post(new Toggled($this->window(), $this->path(), $this->uuid(), $this->checked));
        // the radio that went off is told too (checkbox.c:390, 394-396)
        foreach ($off as $radio) {
            $radio->post(new Toggled($radio->window(), $radio->path(), $radio->uuid(), false));
        }
    }

    /** @var list<Checkbox> the radios the last setChecked(true) turned off */
    private array $clearedRadios = [];

    public function paint(Painter $p): void
    {
        $look = $this->look();
        $b = $this->bounds();
        $mark = $this->mark();
        $box = new Rect($b->x, $b->y + floor(($b->h - $mark) / 2), $mark, $mark);
        $on = $this->on()->value();
        $mix = max(0.0, min(1.0, $on));

        ($this->style()['paint'] ?? 'raven') === 'parts' ? $this->paintParts($p, $box, $mix) : $this->paintRaven($p, $box, $on, $mix);

        $role = $this->isEnabled() ? Role::Text : Role::TextDisabled;
        $p->text($this->label, $b->x + $mark + $look->metric(Metric::Spacing), $this->baselineIn($b), $this->color($role, $look), $this->textStyle());
    }

    /** Raven's own: the box dips under the finger, the mark pops into it (checkbox.c:296-316, 341-362). */
    private function paintRaven(Painter $p, Rect $box, float $on, float $mix): void
    {
        $look = $this->look();
        $hovered = $this->hovered && $this->isEnabled();
        $pressed = $this->isPressed();
        $accent = $look->color($hovered ? Role::AccentHovered : Role::Accent);
        $off = $look->color($pressed ? Role::ControlPressed : ($hovered ? Role::ControlHovered : Role::Control));
        $outline = $look->color(Role::ControlBorder);
        if (! $this->isEnabled()) {
            [$accent, $off, $outline] = [self::fade($accent, 0.4), self::fade($off, 0.5), self::fade($outline, 0.5)];
        }
        $dip = max(0.0, min(1.0, $this->press()->value())) * 1.5;
        $body = new Rect($box->x + $dip, $box->y + $dip, $box->w - 2 * $dip, $box->h - 2 * $dip);
        // glass: round boxes with a ring 1.5 wide (checkbox.c:343-348)
        $glass = $look->material()->isGlass();
        $radius = $glass || $this->isRadio() ? $box->h / 2 : 3.0;
        PartBox::paint($p, $body, self::mix($off, $accent, $mix), $radius, self::mix($outline, $accent, $mix), ($glass ? 1.5 : $look->metric(Metric::BorderWidth)) * (1 - $mix));
        if ($on > 0.05) {
            $p->pushOpacity(max(0.0, min(1.0, $on * 2)));
            $pop = 0.4 + 0.6 * $on;
            if ($this->isRadio()) {
                $dot = $box->h * ($glass ? 0.38 : 0.4) * $pop;
                $p->rect(new Rect($box->x + ($box->w - $dot) / 2, $box->y + ($box->h - $dot) / 2, $dot, $dot), $look->color(Role::AccentText), $dot / 2);
            } else {
                Icons::paint($p, Icons::CHECK, $box->x + $box->w / 2, $box->y + $box->h / 2, $box->h * ($glass ? 0.5 : 0.55) * $pop, $look->color(Role::AccentText));
            }
            $p->popOpacity();
        }
    }

    /** The platforms': Box and Mark parts, off and on ('on' class) eased by the springs (checkbox.c:109-184, 243-257). */
    private function paintParts(Painter $p, Rect $box, float $mix): void
    {
        [$hover, $press] = [$this->hover()->value(), $this->press()->value()];
        if ($this->isRadio()) {
            // the radio takes its state as it is, the dot grows in with the spring (checkbox.c:230-241)
            $this->paintPart($p, $box, $this->box('Radio', $this->checked ? ['on'] : [], $hover, $press));
            if ($mix > 0.01) {
                $d = $this->easedPart('Checkbox', 'Dot', ['on'], $hover, $press);
                $dot = (float) ($d->extra['size'] ?? 0.0) * (0.5 + 0.5 * $mix);
                $p->pushOpacity($mix);
                $this->paintPart($p, new Rect($box->x + ($box->w - $dot) / 2, $box->y + ($box->h - $dot) / 2, $dot, $dot), $d);
                $p->popOpacity();
            }

            return;
        }
        $part = self::mixParts($this->box('Box', [], $hover, $press), $this->box('Box', ['on'], $hover, $press), $mix);
        $this->paintPart($p, $box, $part);
        if ($mix > 0.01) {
            $m = $this->easedPart('Checkbox', 'Mark', ['on'], $hover, $press);
            $p->pushOpacity($mix * (float) ($m->extra['opacity'] ?? 1.0));
            Icons::paint($p, Icons::CHECK, $box->x + $box->w / 2, $box->y + $box->h / 2, (float) ($m->extra['size'] ?? $box->h) * 0.85, $m->text ?? $this->look()->color(Role::AccentText));
            $p->popOpacity();
        }
    }

    /** The Box eased, its bottom edge the fill mixed toward black by shadeBottom (macOS on: checkbox.c:126). */
    private function box(string $name, array $classes, float $hover, float $press): PartLook
    {
        $b = $this->easedPart('Checkbox', $name, $classes, $hover, $press);
        if (! isset($b->extra['shadeBottom']) || $b->background === null) {
            return $b;
        }

        return new PartLook($b->background, $b->border, $b->text, $b->radius, $b->borderWidth, $b->shadow, $b->shadowColor, $b->extra,
            $b->borderTop, self::mix($b->background, new Color(0, 0, 0, 1), (float) $b->extra['shadeBottom']));
    }

    /** rvn_paint_part: its opacity, then the box, round as the rect allows (look.c:363-379). */
    private function paintPart(Painter $p, Rect $r, PartLook $l): void
    {
        $p->pushOpacity((float) ($l->extra['opacity'] ?? 1.0));
        PartBox::paint($p, $r, $l->background, min($l->radius, min($r->w, $r->h) / 2), $l->border, $l->borderWidth, $l->borderTop, $l->borderBottom);
        $p->popOpacity();
    }

    private static function fade(Color $c, float $by): Color
    {
        return new Color($c->red, $c->green, $c->blue, $c->alpha * $by);
    }
}
