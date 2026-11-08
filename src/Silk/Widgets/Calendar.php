<?php

namespace Silk\Widgets;

use DateTimeImmutable;
use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\FontSpec;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Overlay\Popup;
use Silk\Text\Dates;
use Silk\Widgets\Concerns\MeasuresText;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\NutsAndBolts\Color;

/**
 * A DatePicker's calendar (date_picker.c:176-443), shown through the popup layer as a ComboBox's list: the month
 * between its arrows, the days of the week from the picker's first day, six weeks of days in cells of 32. Arrows
 * move the cursor a day or a week, PageUp/PageDown a month (with Shift a year), Home and End to the month's ends;
 * Enter, Space or a click picks; the wheel turns the months. Days outside the picker's range are dimmer and
 * cannot be picked. The click that opened it ends on it and picks nothing.
 */
class Calendar extends Popup
{
    use MeasuresText;

    public const float CELL = 32.0;

    public const float PAD = 8.0;

    public const float WEEKDAYS = 22.0;

    private DateTimeImmutable $month;

    private DateTimeImmutable $cursor;

    private int $hotCell = -1;

    private int $hotArrow = 0;

    private bool $pressed = false;

    private float $wheelRest = 0.0;

    public function __construct(string $name, private readonly DatePicker $picker)
    {
        parent::__construct($name, 0, 0);
        $this->focusable = true;
        $this->showMonth($picker->shownDate());
    }

    /** The first of the month on show. */
    public function month(): DateTimeImmutable
    {
        return $this->month;
    }

    public function cursor(): DateTimeImmutable
    {
        return $this->cursor;
    }

    /** Shows $d's month with the cursor on $d. */
    public function showMonth(DateTimeImmutable $d): static
    {
        $this->cursor = $d->setTime(0, 0);
        $this->month = $this->cursor->modify('first day of this month');

        return $this->invalidatePaint();
    }

    /** The first day drawn: in the month before when the month does not start the week (date_picker.c:243-250). */
    public function gridStart(): DateTimeImmutable
    {
        $lead = ((int) $this->month->format('w') - $this->picker->firstDayOfWeek() + 7) % 7;

        return $this->month->modify("-{$lead} days");
    }

    public function cellRect(DateTimeImmutable $d): ?Rect
    {
        $i = (int) $this->gridStart()->diff($d->setTime(0, 0))->format('%r%a');

        return $i < 0 || $i >= 42 ? null : $this->cell($i);
    }

    /** @return array{Rect, Rect} the previous and the next month's arrows */
    public function arrows(): array
    {
        $b = $this->bounds();
        $size = $this->header();

        return [new Rect($b->x + self::PAD, $b->y + self::PAD, $size, $size), new Rect($b->x + $b->w - self::PAD - $size, $b->y + self::PAD, $size, $size)];
    }

    protected function measureContent(Size $available): Size
    {
        return new Size(self::CELL * 7 + self::PAD * 2, self::PAD * 2 + $this->header() + self::WEEKDAYS + self::CELL * 6);
    }

    public function handle(SilkEvent $e): bool
    {
        switch ($e->type) {
            case EventType::MouseMove:
                [$prev, $next] = $this->arrows();
                $cell = $this->cellAt($e->x, $e->y);
                $arrow = $prev->contains($e->x, $e->y) ? -1 : ($next->contains($e->x, $e->y) ? 1 : 0);
                if ($cell !== $this->hotCell || $arrow !== $this->hotArrow) {
                    [$this->hotCell, $this->hotArrow] = [$cell, $arrow];
                    $this->invalidatePaint();
                }

                return true;
            case EventType::Leave:
                [$this->hotCell, $this->hotArrow] = [-1, 0];
                $this->invalidatePaint();

                return false;
            case EventType::MouseDown:
                if ($e->button !== MouseButton::LEFT) {
                    return true;
                }
                $this->pressed = true;
                [$prev, $next] = $this->arrows();
                $prev->contains($e->x, $e->y) && $this->stepMonth(-1);
                $next->contains($e->x, $e->y) && $this->stepMonth(1);

                return true;
            case EventType::MouseUp:
                if ($e->button !== MouseButton::LEFT || ! $this->pressed) {
                    return true;
                }
                $this->pressed = false;
                $cell = $this->cellAt($e->x, $e->y);
                $cell >= 0 && $this->pick($this->gridStart()->modify("+{$cell} days"));

                return true;
            case EventType::Wheel:
                $this->wheelRest += $e->wheelY;
                $steps = (int) $this->wheelRest;
                $this->wheelRest -= $steps;
                $steps !== 0 && $this->stepMonth($steps);

                return true;
            case EventType::KeyDown:
                $shift = $e->modifiers->shift;
                $c = $this->cursor;
                $to = match ($e->key) {
                    Key::LEFT => $c->modify('-1 day'),
                    Key::RIGHT => $c->modify('+1 day'),
                    Key::UP => $c->modify('-7 days'),
                    Key::DOWN => $c->modify('+7 days'),
                    Key::PAGE_UP => self::addMonths($c, $shift ? -12 : -1),
                    Key::PAGE_DOWN => self::addMonths($c, $shift ? 12 : 1),
                    Key::HOME => $c->modify('first day of this month'),
                    Key::END => $c->modify('last day of this month'),
                    default => null,
                };
                if ($e->key === Key::ENTER || $e->key === Key::NUMPAD_ENTER || $e->key === Key::SPACE) {
                    $this->pick($this->cursor);

                    return true;
                }
                if ($to === null) {
                    // Escape is left to the window, which closes the popup
                    return false;
                }
                $y = (int) $to->format('Y');
                if ($y >= 1 && $y <= 9999) {
                    $this->cursor = $to;
                    $this->hotCell = -1;
                    $to->format('Y-m') === $this->month->format('Y-m') || $this->month = $to->modify('first day of this month');
                    $this->invalidatePaint();
                }

                return true;
            default:
                return false;
        }
    }

    protected function paintSelf(Painter $p): void
    {
        parent::paintSelf($p);
        $look = $this->look();
        $b = $this->bounds();
        $t = $this->tree()?->translator() ?? new \Silk\Text\EnglishTranslator;
        $style = $this->textStyle();
        $text = $this->color(Role::Text, $look);
        $dim = $this->color(Role::TextDisabled, $look);
        $accent = $this->color(Role::Accent, $look);
        // the month, between its arrows
        $title = Dates::monthName((int) $this->month->format('n'), $t).' '.$this->month->format('Y');
        $head = new Rect($b->x + self::PAD, $b->y + self::PAD, $b->w - self::PAD * 2, $this->header());
        $p->text($title, $head->x + ($head->w - $this->textSize($title, $style)->w) / 2, $this->baselineIn($head, $style), $text, $style);
        foreach ($this->arrows() as $k => $arrow) {
            $side = $k === 0 ? -1 : 1;
            $this->hotArrow === $side && $p->rect($arrow, $this->color(Role::RowHovered, $look), 6.0);
            $this->chevron($p, $arrow->x + $arrow->w / 2, $arrow->y + $arrow->h / 2, $side, $text);
        }
        // the days of the week
        $small = new FontSpec($style->family, $style->size * 0.85, $style->weight, $style->italic);
        for ($c = 0; $c < 7; $c++) {
            $name = mb_substr(Dates::dayName($this->picker->firstDayOfWeek() + $c, $t), 0, 2);
            $r = new Rect($b->x + self::PAD + self::CELL * $c, $b->y + self::PAD + $this->header(), self::CELL, self::WEEKDAYS);
            $p->text($name, $r->x + ($r->w - $this->textSize($name, $small)->w) / 2, $this->baselineIn($r, $small), $dim, $small);
        }
        // the days
        $today = new DateTimeImmutable('today');
        $picked = $this->picker->date()?->setTime(0, 0);
        $d = $this->gridStart();
        for ($cell = 0; $cell < 42; $cell++, $d = $d->modify('+1 day')) {
            $box = $this->cell($cell);
            $inner = $box->grow(-2);
            $chosen = $picked !== null && $d == $picked;
            $allowed = $this->picker->allows($d);
            $color = $d->format('n') === $this->month->format('n') ? $text : $dim;
            $allowed || $color = new Color($dim->red, $dim->green, $dim->blue, $dim->alpha * 0.5);
            if ($chosen) {
                $p->rect($inner, $accent, $inner->h / 2);
                $color = $this->color(Role::AccentText, $look);
            } elseif (($d == $this->cursor || $cell === $this->hotCell) && $allowed) {
                $p->rect($inner, $this->color(Role::RowHovered, $look), $inner->h / 2);
            }
            if ($d == $today && ! $chosen) {
                $p->rect($inner, new Color(0, 0, 0, 0), $inner->h / 2, $accent, 1.0);
            }
            $label = $d->format('j');
            $p->text($label, $box->x + ($box->w - $this->textSize($label, $style)->w) / 2, $this->baselineIn($box, $style), $color, $style);
        }
    }

    private function pick(DateTimeImmutable $d): void
    {
        if (! $this->picker->allows($d)) {
            return;
        }
        // close first: the mail may open something else
        $this->close();
        $this->picker->pick($d);
    }

    private function stepMonth(int $months): void
    {
        $m = self::addMonths($this->month, $months);
        $y = (int) $m->format('Y');
        if ($y >= 1 && $y <= 9999) {
            $this->month = $m->modify('first day of this month');
            $this->invalidatePaint();
        }
    }

    /** A month away, the day kept within the month (rvn_date_add_months). */
    private static function addMonths(DateTimeImmutable $d, int $months): DateTimeImmutable
    {
        $first = $d->modify('first day of this month')->modify(($months >= 0 ? '+' : '').$months.' months');

        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), min((int) $d->format('j'), (int) $first->format('t')));
    }

    private function header(): float
    {
        return $this->look()->metric(Metric::RowHeight) + 4;
    }

    private function cell(int $i): Rect
    {
        $b = $this->bounds();
        $top = $b->y + self::PAD + $this->header() + self::WEEKDAYS;

        return new Rect($b->x + self::PAD + self::CELL * ($i % 7), $top + self::CELL * intdiv($i, 7), self::CELL, self::CELL);
    }

    private function cellAt(float $x, float $y): int
    {
        $b = $this->bounds();
        $top = $b->y + self::PAD + $this->header() + self::WEEKDAYS;
        if ($x < $b->x + self::PAD || $y < $top) {
            return -1;
        }
        $col = (int) floor(($x - $b->x - self::PAD) / self::CELL);
        $row = (int) floor(($y - $top) / self::CELL);

        return $col >= 0 && $col < 7 && $row >= 0 && $row < 6 ? $row * 7 + $col : -1;
    }

    /** RVN_ICON_CHEVRON_LEFT/RIGHT, 7 units. */
    private function chevron(Painter $p, float $cx, float $cy, int $dir, Color $c): void
    {
        $s = 3.5;
        $w = max(1.0, $this->look()->metric(Metric::BorderWidth) * 1.5);
        $p->line($cx - $dir * $s / 2, $cy - $s, $cx + $dir * $s / 2, $cy, $c, $w)->line($cx + $dir * $s / 2, $cy, $cx - $dir * $s / 2, $cy + $s, $c, $w);
    }
}
