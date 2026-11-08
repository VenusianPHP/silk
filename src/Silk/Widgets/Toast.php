<?php

namespace Silk\Widgets;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Silk\Core\Tree\Widget;
use Silk\Widgets\Concerns\MeasuresText;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Mail\View\ButtonClicked;
use Surface\NutsAndBolts\Color;

/**
 * `toast(name)`: Raven's snackbar (toast.c), a widget the layout places (usually at the bottom of an Overlay).
 * show() puts up a short message for some seconds, with the kind's dot and, if setAction() gave one, a button
 * ("Undo") that posts ButtonClicked and dismisses the message. A new message replaces the one shown (Replace),
 * waits its turn (Sequence) or stacks up, the oldest at the top (Stack, at most setMaxVisible()). A message rises
 * 14 units as it shows, SNAPPY, and slides to its place in the stack; hidden, the widget lets the pointer through.
 */
class Toast extends Widget
{
    use MeasuresText;

    public const float MAX_WIDTH = 480.0;

    public const float RISE = 14.0;

    public const float GAP = 8.0;

    private ToastKind $kind = ToastKind::Info;

    private ?string $action = null;

    private float $duration = 4.0;

    private ToastQueue $queue = ToastQueue::Replace;

    private int $maxVisible = 3;

    /** @var list<array{text: string, action: ?string, kind: ToastKind, seconds: float, state: string, left: float, appear: Spring, place: Spring, target: float, placed: bool}> */
    private array $messages = [];

    /** The message last shown, or whose button was just clicked. */
    private string $text = '';

    private ?Size $lastSize = null;

    private int $actionHot = -1;

    private int $actionPressed = -1;

    private bool $ticking = false;

    public function __construct(string $name)
    {
        parent::__construct($name);
    }

    /** Puts up $text for $seconds (0: until dismissed), of the current kind or $kind (Rtoast_show, Rtoast_show_kind). */
    public function show(string $text, ?float $seconds = null, ?ToastKind $kind = null): static
    {
        if ($this->queue === ToastQueue::Replace) {
            // the message shown gives its place to the new one, which rises again where it was
            $this->messages = array_values(array_filter($this->messages, fn ($m) => $m['state'] === 'leaving'));
        }
        $this->messages[] = ['text' => $text, 'action' => $this->action, 'kind' => $kind ?? $this->kind, 'seconds' => $seconds ?? $this->duration,
            'state' => 'waiting', 'left' => 0.0, 'appear' => new Spring(0.0, SpringPreset::Snappy), 'place' => new Spring(0.0, SpringPreset::Snappy),
            'target' => 0.0, 'placed' => false];
        $this->promote();

        return $this;
    }

    /** Dismisses the newest message shown (Rtoast_hide). */
    public function hide(): static
    {
        for ($i = count($this->messages) - 1; $i >= 0; $i--) {
            if ($this->messages[$i]['state'] === 'shown') {
                $this->dismiss($i);
                break;
            }
        }

        return $this;
    }

    /** Every message goes (Rtoast_clear). */
    public function clear(): static
    {
        foreach ($this->messages as $i => $m) {
            $m['state'] === 'waiting' ? $this->messages[$i]['state'] = 'gone' : $this->messages[$i]['state'] = 'leaving';
        }
        $this->messages = array_values(array_filter($this->messages, fn ($m) => $m['state'] !== 'gone'));

        return $this->invalidateLayout();
    }

    public function isShown(): bool
    {
        return $this->shownCount() > 0;
    }

    /** Messages shown or waiting (Rtoast_get_count). */
    public function count(): int
    {
        return count(array_filter($this->messages, fn ($m) => $m['state'] !== 'leaving'));
    }

    /** The message last shown, or the one whose button was clicked (Rtoast_get_text). */
    public function text(): string
    {
        return $this->text;
    }

    public function setQueue(ToastQueue $queue): static
    {
        $this->queue = $queue;
        $this->promote();

        return $this;
    }

    public function setMaxVisible(int $count): static
    {
        $this->maxVisible = max(1, $count);
        $this->promote();

        return $this;
    }

    public function setDuration(float $seconds): static
    {
        $this->duration = $seconds;

        return $this;
    }

    /** The kind the next message gets; in Replace mode the message shown changes too. */
    public function setKind(ToastKind $kind): static
    {
        $this->kind = $kind;
        $this->applyCurrent();

        return $this->invalidatePaint();
    }

    /** The button the next message gets (null: none); in Replace mode the message shown changes too. */
    public function setAction(?string $text): static
    {
        $this->action = $text === '' ? null : $text;
        $this->applyCurrent();

        return $this->invalidateLayout();
    }

    public function springs(): array
    {
        return array_merge(...array_map(fn ($m) => [$m['appear'], $m['place']], $this->messages)) ?: [];
    }

    /** Hidden, or between two messages of a stack, the pointer goes through. */
    public function hits(float $x, float $y): bool
    {
        return $this->messageAt($x, $y) >= 0;
    }

    /** Forgets messages that have faded out. */
    public function settleMotion(): void
    {
        parent::settleMotion();
        $before = count($this->messages);
        $this->messages = array_values(array_filter($this->messages,
            fn ($m) => ! ($m['state'] === 'leaving' && $m['appear']->value() <= 0.001 && $m['appear']->atRest())));
        count($this->messages) === $before || $this->invalidateLayout();
    }

    /** The widest message (160 to 480), and the stacked rows (toast.c:197-215). */
    protected function measureContent(Size $available): Size
    {
        $this->startTicking();
        $row = $this->rowHeight();
        [$width, $y, $shown] = [0.0, 0.0, 0];
        foreach ($this->messages as $i => $m) {
            if ($m['state'] !== 'shown') {
                continue;
            }
            $width = max($width, $this->messageWidth($m));
            // the oldest at the top, the newest nearest the edge the toast is put against
            $this->messages[$i]['target'] = $y;
            $y += $row + self::GAP;
            $shown++;
        }
        if ($shown === 0) {
            return $this->lastSize ?? new Size(160.0, $row);
        }

        return $this->lastSize = new Size(max(160.0, min(self::MAX_WIDTH, $width)), $y - self::GAP);
    }

    public function handle(SilkEvent $e): bool
    {
        if (! $this->isShown()) {
            return false;
        }
        switch ($e->type) {
            case EventType::MouseMove:
                $over = $this->actionAt($e->x, $e->y);
                if ($over !== $this->actionHot) {
                    $this->actionHot = $over;
                    $this->invalidatePaint();
                }

                return $this->messageAt($e->x, $e->y) >= 0;
            case EventType::Leave:
                $this->actionHot = -1;
                $this->invalidatePaint();

                return false;
            case EventType::MouseDown:
                if ($e->button === MouseButton::LEFT) {
                    $this->actionPressed = $this->actionAt($e->x, $e->y);
                    $this->invalidatePaint();
                }

                return $this->messageAt($e->x, $e->y) >= 0;
            case EventType::MouseUp:
                $pressed = $this->actionPressed;
                if ($e->button !== MouseButton::LEFT || $pressed < 0) {
                    return true;
                }
                $this->actionPressed = -1;
                $this->actionAt($e->x, $e->y) === $pressed ? $this->clickAction($pressed) : $this->invalidatePaint();

                return true;
            default:
                return false;
        }
    }

    public function paint(Painter $p): void
    {
        foreach ($this->messages as $i => $m) {
            if ($m['state'] === 'waiting') {
                continue;
            }
            $this->paintMessage($p, $i, min(1.0, max(0.0, $m['appear']->value())));
        }
    }

    /** toast.c:256-290 */
    private function paintMessage(Painter $p, int $i, float $shown): void
    {
        $m = $this->messages[$i];
        if ($shown <= 0.001) {
            return;
        }
        $look = $this->look();
        $b = $this->bounds();
        $row = $this->rowHeight();
        $pad = $this->padding();
        $radius = min(12.0, $row / 2);
        // a message that arrives starts at its place; then it slides to the next one
        if (! $m['placed']) {
            $m['place']->snap($m['target']);
            $this->messages[$i]['placed'] = true;
        } else {
            $m['place']->target($m['target']);
        }
        $r = new Rect($b->x, $b->y + $m['place']->value() + (1 - $shown) * self::RISE, $b->w, $row);
        $p->shadow($r, $radius, 16.0, self::fade($this->color(Role::Shadow, $look), $shown));
        $p->rect($r, self::fade($this->color(Role::Tooltip, $look), $shown), $radius);
        // the kind: a dot before the message
        $p->rect(new Rect($r->x + $pad, $r->y + $r->h / 2 - 4, 8, 8), self::fade($this->color($m['kind']->role(), $look), $shown), 4.0);
        $action = $this->actionRect($i);
        $right = $r->w - $pad - ($action->w > 0 ? $action->w + 4 : 0.0);
        $area = new Rect($r->x + $pad + 14, $r->y, max(0.0, $right - $pad - 14), $r->h);
        $style = $this->textStyle();
        $p->pushClip($area);
        $p->text($m['text'], $area->x, $this->baselineIn($area, $style), self::fade($this->color(Role::TooltipText, $look), $shown), $style);
        $p->popClip();
        if ($action->w > 0) {
            $accent = self::fade($this->color(Role::Accent, $look), $shown);
            $a = new Rect($action->x, $r->y + 4, $action->w, $action->h);
            if ($this->actionHot === $i || $this->actionPressed === $i) {
                $p->rect($a, self::fade($accent, $this->actionPressed === $i ? 0.3 : 0.18), min(8.0, $a->h / 2));
            }
            $p->text($m['action'], $a->x + ($a->w - $this->textSize($m['action'], $style)->w) / 2, $this->baselineIn($a, $style), $accent, $style);
        }
    }

    private function rowHeight(): float
    {
        return $this->look()->metric(Metric::ControlHeight) + 14;
    }

    private function padding(): float
    {
        return $this->look()->metric(Metric::PaddingX) + 4;
    }

    /** @param array{text: string, action: ?string} $m */
    private function messageWidth(array $m): float
    {
        return ceil($this->textSize($m['text'])->w) + $this->padding() * 2 + 12 + $this->actionWidth($m);
    }

    /** @param array{action: ?string} $m */
    private function actionWidth(array $m): float
    {
        return ($m['action'] ?? '') === '' ? 0.0 : ceil($this->textSize($m['action'])->w) + $this->padding() * 2;
    }

    /** Where message $i's row is going, in layout coordinates (for clicks). */
    private function rowRect(int $i): Rect
    {
        $b = $this->bounds();

        return new Rect($b->x, $b->y + $this->messages[$i]['target'], $b->w, $this->rowHeight());
    }

    private function actionRect(int $i): Rect
    {
        $row = $this->rowRect($i);
        $w = $this->actionWidth($this->messages[$i]);

        return new Rect($row->x + $row->w - $w - 4, $row->y + 4, $w, max(0.0, $row->h - 8));
    }

    private function messageAt(float $x, float $y): int
    {
        foreach ($this->messages as $i => $m) {
            if ($m['state'] === 'shown' && $this->rowRect($i)->contains($x, $y)) {
                return $i;
            }
        }

        return -1;
    }

    private function actionAt(float $x, float $y): int
    {
        $i = $this->messageAt($x, $y);

        return $i >= 0 && $this->actionWidth($this->messages[$i]) > 0 && $this->actionRect($i)->contains($x, $y) ? $i : -1;
    }

    private function clickAction(int $i): void
    {
        // (the mail's moment: text() gives the message whose button was clicked)
        $this->text = $this->messages[$i]['text'];
        $this->dismiss($i);
        $this->post(new ButtonClicked($this->window(), $this->path(), $this->uuid()));
    }

    private function shownCount(): int
    {
        return count(array_filter($this->messages, fn ($m) => $m['state'] === 'shown'));
    }

    /** Shows the messages that wait while there is room (toast.c:122-139). */
    private function promote(): void
    {
        $room = $this->queue === ToastQueue::Stack ? $this->maxVisible : 1;
        $shown = $this->shownCount();
        foreach ($this->messages as $i => $m) {
            if ($shown >= $room) {
                break;
            }
            if ($m['state'] !== 'waiting') {
                continue;
            }
            $this->messages[$i]['state'] = 'shown';
            $this->messages[$i]['left'] = $m['seconds'];
            $m['appear']->snap(0.0)->target(1.0);
            $this->messages[$i]['placed'] = false;
            $this->text = $m['text'];
            $shown++;
        }
        $this->startTicking();
        $this->actionHot = $this->actionPressed = -1;
        $this->invalidateLayout();
    }

    private function dismiss(int $i): void
    {
        if ($this->messages[$i]['state'] === 'waiting') {
            array_splice($this->messages, $i, 1);
        } else {
            $this->messages[$i]['state'] = 'leaving';
            $this->messages[$i]['appear']->target(0.0);
        }
        $this->promote();
    }

    /** The messages' time: Raven's timers, on the tree's clock. */
    private function startTicking(): void
    {
        if ($this->ticking || $this->shownCount() === 0 || ($tree = $this->tree()) === null) {
            return;
        }
        $this->ticking = true;
        $tree->tick(function (float $dt): bool {
            foreach ($this->messages as $i => $m) {
                if ($m['state'] === 'shown' && $m['seconds'] > 0) {
                    $this->messages[$i]['left'] -= $dt;
                    $this->messages[$i]['left'] <= 1e-9 && $this->dismiss($i);
                }
            }
            $this->ticking = $this->shownCount() > 0;

            return $this->ticking;
        });
    }

    /** In Replace mode the kind and the button also change the message shown. */
    private function applyCurrent(): void
    {
        if ($this->queue !== ToastQueue::Replace) {
            return;
        }
        for ($i = count($this->messages) - 1; $i >= 0; $i--) {
            if ($this->messages[$i]['state'] === 'shown') {
                [$this->messages[$i]['kind'], $this->messages[$i]['action']] = [$this->kind, $this->action];

                return;
            }
        }
    }

    private static function fade(Color $c, float $a): Color
    {
        return new Color($c->red, $c->green, $c->blue, $c->alpha * $a);
    }
}
