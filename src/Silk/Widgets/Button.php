<?php

namespace Silk\Widgets;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Motion;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\State;
use Silk\Contracts\Look\SurfaceKind;
use Silk\Painter\Surfaces;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Tree\Widget;
use Silk\Painter\PartBox;
use Silk\Widgets\Concerns\EasesParts;
use Silk\Widgets\Concerns\Hoverable;
use Silk\Widgets\Concerns\MeasuresText;
use Silk\Widgets\Concerns\Pressable;
use Surface\Contracts\Windows\Mail\View\ButtonClicked;
use Surface\NutsAndBolts\Color;

/** `button(name, label)` as TKButton; posts ButtonClicked on a click. */
class Button extends Widget
{
    use EasesParts, Hoverable, MeasuresText, Pressable;

    private ButtonStyle $style = ButtonStyle::Normal;

    /** Raven's press squash. */
    public const float SQUASH = 0.09;

    /** A glass button is softer (button.c:87). */
    public const float GLASS_SQUASH = 0.13;

    public function __construct(string $name, private string $label)
    {
        parent::__construct($name);
        $this->focusable = true;
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

    /** Raven's Rbutton_set_style; TK's button has none, so this is Silk's own. The style is a class of the part look. */
    public function setStyle(ButtonStyle $style): static
    {
        $this->style = $style;

        return $this->invalidatePaint();
    }

    public function style(): ButtonStyle
    {
        return $this->style;
    }

    protected function measureContent(Size $available): Size
    {
        $look = $this->look();

        return new Size(
            $this->textSize($this->label)->w + 2 * $look->metric(Metric::PaddingX),
            $look->metric(Metric::ControlHeight),
        );
    }

    public function springs(): array
    {
        return [$this->hover(), $this->press()];
    }

    public function handle(SilkEvent $e): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }
        $this->hoverEvent($e);

        return $this->pressEvent($e);
    }

    protected function activate(): void
    {
        $this->post(new ButtonClicked($this->window(), $this->path(), $this->uuid()));
    }

    /**
     * A capsule of glass that lights up under the pointer (button.c:114-136): it grows 0.6 × hover each side; the default
     * button is the accent's glass, a flat one shows its glass only while used.
     */
    private function paintGlass(Painter $p, Rect $squashed, Rect $b): void
    {
        $look = $this->look();
        $hover = max(0.0, min(1.0, $this->hover()->value()));
        $press = $this->press()->value();
        $lift = $hover * 0.6;
        $rect = new Rect($squashed->x - $lift, $squashed->y - $lift, $squashed->w + 2 * $lift, $squashed->h + 2 * $lift);
        $radius = $rect->h * 0.5;
        $enabled = $this->isEnabled();
        $text = $this->color(Role::Text, $look);
        if ($this->style === ButtonStyle::Primary) {
            Surfaces::paint($p, $look, $rect, $radius, SurfaceKind::Accent, $hover, $press, $enabled);
            $enabled && $text = $this->color(Role::AccentText, $look);
        } elseif ($this->style === ButtonStyle::Flat) {
            // flat buttons only show their glass while they are used
            $shown = max(0.0, min(1.0, max($hover, $press)));
            if ($shown > 0.01) {
                $p->pushOpacity($shown);
                Surfaces::paint($p, $look, $rect, $radius, SurfaceKind::Control, $hover, $press, $enabled);
                $p->popOpacity();
            }
        } else {
            Surfaces::paint($p, $look, $rect, $radius, SurfaceKind::Control, $hover, $press, $enabled);
        }
        $p->text($this->label, $b->x + ($b->w - $this->textSize($this->label)->w) / 2, $this->baselineIn($b), $text, $this->textStyle());
    }

    /** Inside a header bar (common.c:227-233). */
    private function onBar(): bool
    {
        for ($w = $this->parent(); $w !== null; $w = $w->parent()) {
            if ($w instanceof HeaderBar) {
                return true;
            }
        }

        return false;
    }

    /** macOS 26 (button.c:92-110): the buttons of a bar are capsules of glass, the primary one tinted; flat ones show it only while pressed. */
    private function paintLiquidBar(Painter $p, Rect $r, Rect $b): void
    {
        $look = $this->look();
        $press = max(0.0, min(1.0, $this->press()->value()));
        $radius = $r->h / 2;
        $text = $this->color(Role::Text, $look);
        if ($this->style === ButtonStyle::Primary) {
            Surfaces::paint($p, $look, $r, $radius, SurfaceKind::Accent, 0.0, $press);
            $this->isEnabled() && $text = $this->color(Role::AccentText, $look);
        } elseif ($this->style !== ButtonStyle::Flat || $press > 0.01) {
            $flat = $this->style === ButtonStyle::Flat;
            $flat && $p->pushOpacity($press);
            Surfaces::paint($p, $look, $r, $radius, SurfaceKind::Control, 0.0, $press);
            $flat && $p->popOpacity();
        }
        $p->text($this->label, $b->x + ($b->w - $this->textSize($this->label)->w) / 2, $this->baselineIn($b), $text, $this->textStyle());
    }

    public function paint(Painter $p): void
    {
        $look = $this->look();
        // the colours of the states blend with the springs (button.c:134, rvn_part_look_eased)
        $body = $this->easedPart('Button', 'Body', $this->style === ButtonStyle::Normal ? [] : [$this->style->value], $this->hover()->value(), max(0.0, $this->press()->value()));
        $bg = $body->background;
        [$top, $bottom] = [$body->borderTop, $body->borderBottom];
        // macOS's default button: a lighter top edge, a darker bottom one, from its fill (platform_looks.c:87-92)
        if ($bg !== null && (float) ($body->extra['lightTop'] ?? 0.0) > 0) {
            $top = self::mix($bg, new Color(1, 1, 1, 1), (float) $body->extra['lightTop']);
        }
        if ($bg !== null && (float) ($body->extra['shadeBottom'] ?? 0.0) > 0) {
            $bottom = self::mix($bg, new Color(0, 0, 0, 1), (float) $body->extra['shadeBottom']);
        }

        // grow_x = +0.45 * amount * press, grow_y = -amount * press, about the centre
        // springy looks only (common.c:197)
        $press = $look->motion() === Motion::Springy ? $this->press()->value() : 0.0;
        $b = $this->bounds();
        $squash = $look->material()->isGlass() ? self::GLASS_SQUASH : self::SQUASH;
        $w = $b->w * (1 + 0.45 * $squash * $press);
        $h = $b->h * (1 - $squash * $press);
        $r = new Rect($b->x + ($b->w - $w) / 2, $b->y + ($b->h - $h) / 2, $w, $h);

        if ($look->material()->isGlass()) {
            $this->paintGlass($p, $r, $b);

            return;
        }

        if ($look->liquid() && $this->onBar()) {
            $this->paintLiquidBar($p, $r, $b);

            return;
        }

        if ($body->shadow > 0 && $body->shadowColor !== null) {
            // as Raven's part shadow: under the squashed body; the engine offsets it by blur/4
            $p->shadow($r, $body->radius, $body->shadow, $body->shadowColor);
        }
        PartBox::paint($p, $r, $bg, $body->radius, $body->border, $body->borderWidth, $top, $bottom);

        $text = $body->text ?? $this->color(Role::Text, $look);
        $p->text($this->label, $b->x + ($b->w - $this->textSize($this->label)->w) / 2, $this->baselineIn($b), $text, $this->textStyle());
    }
}
