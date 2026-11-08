<?php

namespace Silk\Widgets;

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
use Silk\Core\Tree\Container;
use Silk\Painter\Icons;
use Silk\Widgets\Concerns\MeasuresText;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Mail\View\Toggled;
use Surface\NutsAndBolts\Color;

/**
 * A header and a body column; Raven widgets/expander.c. Its height follows a layout spring between closed (the header)
 * and open (header + body): BOUNCY opening, so the content below bounces with it, SNAPPY closing. A header click,
 * Enter or Space toggles and posts Toggled; setOpen() posts nothing. Raven's own header bar, macOS's disclosure and
 * GNOME's expander row (a card) come from the look.
 */
class Expander extends Container
{
    use MeasuresText;

    private Column $body;

    private Spring $openness;

    private float $measuredAt = 0.0;

    private bool $hot = false;

    public function __construct(string $name, private string $title, private bool $open = false)
    {
        parent::__construct($name);
        $this->focusable = true;
        $this->add($this->body = new Column('body', 6, 0));
        $this->openness = new Spring($open ? 1.0 : 0.0, SpringPreset::Bouncy);
        $this->body->setVisible($open);
    }

    public function body(): Column
    {
        return $this->body;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this->invalidateLayout();
    }

    /** Posts nothing; opens on BOUNCY, closes on SNAPPY (expander.c:149). */
    public function setOpen(bool $open): static
    {
        if ($open === $this->open) {
            return $this;
        }
        $this->open = $open;
        $this->openness->preset = $open ? SpringPreset::Bouncy : SpringPreset::Snappy;
        $this->openness->target($open ? 1.0 : 0.0);
        $open && $this->body->setVisible(true);

        return $this->invalidateLayout();
    }

    public function springs(): array
    {
        return [$this->openness];
    }

    /** Re-measures while the height moves; hides the body once closed. */
    public function settleMotion(): void
    {
        parent::settleMotion();
        if ($this->openness->value() !== $this->measuredAt) {
            $this->invalidateLayout();
        }
        if (! $this->open && $this->openness->atRest() && $this->body->isVisible()) {
            $this->body->setVisible(false);
        }
    }

    /** @return array<string, float|string> */
    private function style(): array
    {
        return $this->look()->part('Expander', 'Body', State::Normal)->extra;
    }

    /** Raven: a row and 4; macOS a row; GNOME 50 (expander.c:29-36). */
    private function header(): float
    {
        return (float) ($this->style()['header'] ?? $this->look()->metric(Metric::RowHeight) + 4);
    }

    public function headerRect(): Rect
    {
        $b = $this->bounds();

        return new Rect($b->x, $b->y, $b->w, $this->header());
    }

    /** The header, the title and PaddingX wide; the body by the spring, rounded (expander.c:94-109). */
    protected function measureContent(Size $available): Size
    {
        $header = $this->header();
        $this->measuredAt = $this->openness->value();
        $w = $header + $this->textSize($this->title)->w + $this->look()->metric(Metric::PaddingX);
        if ($this->measuredAt <= 0 || ! $this->body->isVisible()) {
            return new Size($w, $header);
        }
        $body = $this->body->measure(new Size($available->w, max(0.0, $available->h - $header)));

        return new Size(max($w, $body->w), $header + floor($body->h * $this->measuredAt + 0.5));
    }

    /** While it moves the body keeps its size and is uncovered by the expander's edge (expander.c:111-122). */
    protected function arrangeContent(Rect $r): void
    {
        $header = $this->header();
        $h = max($r->h - $header, $this->body->measure(new Size($r->w, 1e9))->h);
        $this->body->arrange(new Rect($r->x, $r->y + $header, $r->w, $h));
    }

    public function handle(SilkEvent $e): bool
    {
        if ($e->type === EventType::MouseMove || $e->type === EventType::Leave) {
            $hot = $e->type === EventType::MouseMove && $this->headerRect()->contains($e->x, $e->y);
            if ($hot !== $this->hot) {
                $this->hot = $hot;
                $this->invalidatePaint();
            }

            return false;
        }
        $hit = $e->type === EventType::MouseDown && $e->button === MouseButton::LEFT && $this->headerRect()->contains($e->x, $e->y);
        $key = $e->type === EventType::KeyDown && ($e->key === Key::ENTER || $e->key === Key::SPACE);
        if (! $this->isEnabled() || (! $hit && ! $key)) {
            return false;
        }
        $this->setOpen(! $this->open);
        $this->post(new Toggled($this->window(), $this->path(), $this->uuid(), $this->open));

        return true;
    }

    public function paint(Painter $p): void
    {
        match ($this->style()['paint'] ?? 'raven') {
            'disclosure' => $this->paintDisclosure($p),
            'card' => $this->paintCard($p),
            default => $this->paintRaven($p),
        };
        $t = $this->openness->value();
        if ($this->body->isVisible() && $t > 0.001) {
            $p->pushClip($this->bounds());
            $this->body->render($p);
            $p->popClip();
        }
    }

    private function text(): Color
    {
        return $this->color($this->isEnabled() ? Role::Text : Role::TextDisabled, $this->look());
    }

    /** Raven's own: a Header bar (HeaderHovered hot), the chevron turning with the content, the title after it (expander.c:235-245). */
    private function paintRaven(Painter $p): void
    {
        $look = $this->look();
        $h = $this->headerRect();
        // expander.c:197: the header of a glass look is rounded
        $p->rect($h, $look->color($this->hot && $this->isEnabled() ? Role::HeaderHovered : Role::Header), $look->material()->isGlass() ? 10.0 : 0.0);
        Icons::chevron($p, $h->x + $h->h / 2, $h->y + $h->h / 2, 8.0, $this->openness->value() * M_PI / 2, $this->text());
        $this->paintTitle($p, new Rect($h->x + $h->h, $h->y, max(0.0, $h->w - $h->h), $h->h), $this->text());
    }

    /** macOS: a disclosure, the chevron before the title, nothing around (expander.c:209-222). */
    private function paintDisclosure(Painter $p): void
    {
        $h = $this->headerRect();
        $arrow = $this->look()->part('Expander', 'Arrow', $this->hot ? State::Hover : State::Normal)->text ?? $this->text();
        Icons::chevron($p, $h->x + 9, $h->y + $h->h / 2, 7.0, max(0.0, min(1.0, $this->openness->value())) * M_PI / 2, $arrow);
        $this->paintTitle($p, new Rect($h->x + 20, $h->y, max(0.0, $h->w - 20), $h->h), $this->text());
    }

    /** GNOME: an expander row, a card; open, a content card hangs under it; the chevron on a button at the right (expander.c:38-92). */
    private function paintCard(Painter $p): void
    {
        $look = $this->look();
        $b = $this->bounds();
        $hb = $this->header();
        $open = max(0.0, min(1.0, $this->openness->value()));
        $state = ! $this->isEnabled() ? State::Disabled : ($this->hot ? State::Hover : State::Normal);
        if ($open > 0 && $b->h > $hb + 0.5) {
            $c = $look->part('Expander', 'Content', $state);
            $p->rect(new Rect($b->x, $b->y + $hb - 1, $b->w, $b->h - $hb + 1), $c->background ?? new Color(0, 0, 0, 0), [0.0, 0.0, $c->radius, $c->radius], $c->border, $c->borderWidth);
        }
        $head = $look->part('Expander', 'Header', $state);
        // open, its bottom corners square off to meet the content
        $r = $head->radius;
        $p->rect(new Rect($b->x, $b->y, $b->w, $hb), $head->background ?? new Color(0, 0, 0, 0), [$r, $r, $r * (1 - $open), $r * (1 - $open)], $head->border, $head->borderWidth);
        $this->paintTitle($p, new Rect($b->x + 16, $b->y, max(0.0, $b->w - 64), $hb), $head->text ?? $this->text());
        $button = new Rect($b->x + $b->w - 40, $b->y + ($hb - 32) / 2, 32, 32);
        $arrow = $look->part('Expander', 'Arrow', $state);
        $arrow->background !== null && $p->rect($button, $arrow->background, $arrow->radius);
        // down closed, up open (rvn_fluent_paint_chevron without its icon font: the chevron at 0.62)
        Icons::chevron($p, $button->x + 16, $button->y + 16, 12.0 * 0.62, $open > 0.5 ? -M_PI / 2 : M_PI / 2, $arrow->text ?? $this->text());
    }

    private function paintTitle(Painter $p, Rect $r, Color $ink): void
    {
        $p->pushClip($r);
        $p->text($this->title, $r->x, $this->baselineIn($r), $ink, $this->textStyle());
        $p->popClip();
    }
}
