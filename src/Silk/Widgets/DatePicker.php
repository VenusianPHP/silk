<?php

namespace Silk\Widgets;

use Silk\Painter\Surfaces;
use Silk\Contracts\Look\SurfaceKind;
use DateTimeImmutable;
use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\State;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Overlay\Placement;
use Silk\Core\Tree\Widget;
use Silk\Painter\PartBox;
use Silk\Text\Dates;
use Silk\Widgets\Concerns\Hoverable;
use Silk\Widgets\Concerns\MeasuresText;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Mail\View\DateChanged;
use Surface\NutsAndBolts\Color;

/**
 * `datepicker(name, date)` as TKDatepicker (date(), setDate(), DateChanged; the date may be null, and the field then
 * shows today), drawn and driven as Raven's (date_picker.c): a button field showing the date in a format
 * (YYYY-MM-DD by default) and a little calendar, that drops down the Calendar. Up and Down step a day, Alt with
 * them, Enter or Space open it. Raven's extras: setFormat, setMinDate/setMaxDate (days outside are dimmer and
 * cannot be chosen), setFirstDayOfWeek (0 Sunday, the default). Month and day names come from the translator.
 */
class DatePicker extends Widget
{
    use Hoverable, MeasuresText;

    private ?DateTimeImmutable $date;

    private ?string $format = null;

    private ?DateTimeImmutable $min = null;

    private ?DateTimeImmutable $max = null;

    private int $firstDay = 0;

    private ?Calendar $calendar = null;

    private bool $hasFocus = false;

    public function __construct(string $name, ?DateTimeImmutable $date = null)
    {
        parent::__construct($name);
        $this->focusable = true;
        $this->date = $date?->setTime(0, 0);
    }

    public function date(): ?DateTimeImmutable
    {
        return $this->date;
    }

    /** Posts nothing. */
    public function setDate(?DateTimeImmutable $date): static
    {
        $this->date = $date?->setTime(0, 0);

        return $this->invalidateLayout();
    }

    /** The date the field shows and the calendar opens on: the date, or today. */
    public function shownDate(): DateTimeImmutable
    {
        return $this->date ?? new DateTimeImmutable('today');
    }

    /** YYYY, MMMM, MMM, MM, M, DD, D, dddd, ddd; null: YYYY-MM-DD. */
    public function setFormat(?string $format): static
    {
        $this->format = $format === '' ? null : $format;

        return $this->invalidateLayout();
    }

    public function setMinDate(?DateTimeImmutable $d): static
    {
        $this->min = $d?->setTime(0, 0);

        return $this->invalidatePaint();
    }

    public function setMaxDate(?DateTimeImmutable $d): static
    {
        $this->max = $d?->setTime(0, 0);

        return $this->invalidatePaint();
    }

    /** 0 Sunday .. 6 Saturday. */
    public function setFirstDayOfWeek(int $day): static
    {
        $this->firstDay = (($day % 7) + 7) % 7;

        return $this->invalidatePaint();
    }

    public function firstDayOfWeek(): int
    {
        return $this->firstDay;
    }

    public function allows(DateTimeImmutable $d): bool
    {
        $d = $d->setTime(0, 0);
        $y = (int) $d->format('Y');

        return $y >= 1 && $y <= 9999 && ($this->min === null || $d >= $this->min) && ($this->max === null || $d <= $this->max);
    }

    public function calendar(): ?Calendar
    {
        return $this->calendar;
    }

    public function isOpen(): bool
    {
        return $this->calendar?->isOpen() ?? false;
    }

    /** The field's text. */
    public function text(): string
    {
        return Dates::format($this->format ?? 'YYYY-MM-DD', $this->shownDate(), $this->tree()?->translator() ?? new \Silk\Text\EnglishTranslator);
    }

    /** @internal the calendar's pick: the date, and DateChanged when it changed */
    public function pick(DateTimeImmutable $d): void
    {
        $this->set($d, true);
    }

    public function springs(): array
    {
        return [$this->hover()];
    }

    /** The text (at least 72) + padding each side + a calendar of 0.8 the height (date_picker.c:496-503). */
    protected function measureContent(Size $available): Size
    {
        $h = $this->look()->metric(Metric::ControlHeight);

        return new Size(ceil(max($this->textSize($this->text())->w, 72.0) + $this->look()->metric(Metric::PaddingX) * 2 + $h * 0.8), $h);
    }

    public function handle(SilkEvent $e): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }
        $this->hoverEvent($e);
        switch ($e->type) {
            case EventType::FocusGained:
            case EventType::FocusLost:
                $this->hasFocus = $e->type === EventType::FocusGained;
                $this->invalidatePaint();

                return false;
            case EventType::MouseDown:
                if ($e->button !== MouseButton::LEFT) {
                    return false;
                }
                // when the calendar is open, the click that closes it never reaches here
                $this->open();

                return true;
            case EventType::KeyDown:
                if ($e->key === Key::UP || $e->key === Key::DOWN) {
                    if ($e->modifiers->alt) {
                        $this->open();

                        return true;
                    }
                    $this->set($this->shownDate()->modify($e->key === Key::UP ? '-1 day' : '+1 day'), true);

                    return true;
                }
                if ($e->key === Key::ENTER || $e->key === Key::NUMPAD_ENTER || $e->key === Key::SPACE) {
                    $this->open();

                    return true;
                }

                return false;
            default:
                return false;
        }
    }

    public function paint(Painter $p): void
    {
        $look = $this->look();
        $b = $this->bounds();
        $enabled = $this->isEnabled();
        $open = $this->isOpen();
        $state = ! $enabled ? State::Disabled : ($open ? State::Pressed : State::Normal);
        $body = $look->part('DatePicker', 'Body', $state);
        $fill = $body->background;
        if ($enabled && ! $open) {
            $fill = self::mix($fill, $look->part('DatePicker', 'Body', State::Hover)->background, $this->hover()->value());
        }
        if ($look->material()->isGlass()) {
            // date_picker.c:512: a control of glass; it stays pressed while the calendar is open
            Surfaces::paint($p, $look, $b, $b->h / 2, SurfaceKind::Control, max(0.0, min(1.0, $this->hover()->value())), $open ? 1.0 : 0.0, $enabled);
        } else {
            PartBox::paint($p, $b, $fill, $body->radius, $body->border, $body->borderWidth, $body->borderTop, $body->borderBottom);
        }
        $text = $body->text ?? $this->color($enabled ? Role::Text : Role::TextDisabled, $look);
        $pad = $look->metric(Metric::PaddingX);
        $icon = $b->h * 0.8;
        $area = new Rect($b->x + $pad, $b->y, max(0.0, $b->w - $pad - $icon), $b->h);
        $style = $this->textStyle();
        $p->pushClip($area);
        $p->text($this->text(), $area->x, $this->baselineIn($area, $style), $text, $style);
        $p->popClip();
        // a little calendar: a sheet with a binding along its top (date_picker.c:547-553)
        $g = new Rect($b->x + $b->w - $icon / 2 - 8, $b->y + $b->h / 2 - 6, 14, 13);
        $p->rect($g, new Color(0, 0, 0, 0), 2.5, $text, 1.2);
        $p->rect(new Rect($g->x, $g->y + 3, $g->w, 1.2), $text);
        $p->rect(new Rect($g->x + 3.5, $g->y - 1.5, 1.2, 3), $text);
        $p->rect(new Rect($g->x + $g->w - 4.7, $g->y - 1.5, 1.2, 3), $text);
    }

    private function set(DateTimeImmutable $d, bool $notify): void
    {
        $d = $d->setTime(0, 0);
        if (! $this->allows($d) || ($this->date !== null && $d == $this->date)) {
            return;
        }
        $this->date = $d;
        $this->invalidateLayout();
        $notify && $this->post(new DateChanged($this->window(), $this->path(), $this->uuid(), $d));
    }

    /** date_picker.c:467-485 */
    private function open(): void
    {
        if ($this->tree() === null || $this->isOpen()) {
            return;
        }
        $this->calendar = new Calendar($this->name().'-calendar', $this);
        $this->calendar->open($this, Placement::Below);
        $this->invalidatePaint();
    }
}
