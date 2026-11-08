<?php

namespace Silk\Widgets;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Host\WindowAction;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\PartLook;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\State;
use Silk\Contracts\Look\SurfaceKind;
use Silk\Contracts\Painter\Painter;
use Silk\Contracts\Widget\Align;
use Silk\Contracts\Widget\Slot;
use Silk\Contracts\Widget\Widget as WidgetContract;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Silk\Core\Tree\Container;
use Silk\Painter\Surfaces;
use Silk\Widgets\Concerns\MeasuresText;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\HitArea;
use Surface\Contracts\Windows\Mail\View\ButtonClicked;
use Surface\NutsAndBolts\Color;

/**
 * `headerBar(name, title)`: Raven's title bar for a window that draws its own (header_bar.c). One widget: it paints
 * the title and the window buttons itself and lays its children out as a row, from the left and, for those set
 * to Align::End, from the right; a child with grow shares what is left. The buttons sit on the system's side
 * (the coloured lights at the left on macOS, else at the right: flat buttons, or the look's 'round' ones), only
 * those the host's window offers and setButtons() allows. The title (the window's, unless set) sits in the
 * middle of the window when it fits there, else in the middle of the room left; setNotch() hangs it in a dark tab.
 * A press and release on a button posts ButtonClicked (<bar>.<minimize|maximize|close>) and asks the window; the
 * hover springs BOUNCY, the press SNAPPY, and a click kicks the hover +6. hitArea() says what drags the window.
 * All its colours and sizes are the HeaderBar part looks.
 */
class HeaderBar extends Container
{
    use MeasuresText, Widgets;

    public const float SPACING = 6.0;

    public const float PADDING = 8.0;

    /** Raven's button order on each side (header_bar.c:92-102). */
    private const array RIGHT = [WindowAction::Minimize, WindowAction::Maximize, WindowAction::Close];

    private const array LEFT = [WindowAction::Close, WindowAction::Minimize, WindowAction::Maximize];

    /** @var array<string, bool> what setButtons() allows */
    private array $allowed = ['minimize' => true, 'maximize' => true, 'close' => true];

    /** null: the system's (macOS left, else right). */
    private ?Align $side = null;

    private bool $titleLeft = false;

    private bool $noBackground = false;

    private bool $notch = false;

    /** @var array<string, Spring> */
    private array $hover = [];

    /** @var array<string, Spring> */
    private array $press = [];

    /** The lights' glyphs show while the pointer is on one of them. */
    private Spring $glyphs;

    private ?WindowAction $hot = null;

    private ?WindowAction $down = null;

    /** The room the children leave for the title. */
    private float $gapX0 = 0.0;

    private float $gapX1 = 0.0;

    public function __construct(string $name, private ?string $title = null)
    {
        parent::__construct($name);
        foreach (WindowAction::cases() as $a) {
            $this->hover[$a->value] = new Spring(0.0, SpringPreset::Bouncy);
            $this->press[$a->value] = new Spring(0.0, SpringPreset::Snappy);
        }
        $this->glyphs = new Spring(0.0, SpringPreset::Snappy);
    }

    /** The title shown: the one set, else the window's. */
    public function title(): string
    {
        return $this->title ?? $this->tree()?->windowActions()?->windowTitle() ?? '';
    }

    /** Null: the window's title. */
    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this->invalidateLayout();
    }

    public function setButtons(bool $minimize, bool $maximize, bool $close): static
    {
        $this->allowed = ['minimize' => $minimize, 'maximize' => $maximize, 'close' => $close];

        return $this->invalidateLayout();
    }

    /** Align::Start or Align::End; null: the system's side. */
    public function setButtonSide(?Align $side): static
    {
        $this->side = $side;

        return $this->invalidateLayout();
    }

    public function setTitleAlign(bool $left): static
    {
        $this->titleLeft = $left;

        return $this->invalidatePaint();
    }

    public function setNoBackground(bool $none): static
    {
        $this->noBackground = $none;

        return $this->invalidatePaint();
    }

    /** TitleStyle="Notch": the title in a dark tab hanging from the top edge. */
    public function setNotch(bool $notch): static
    {
        $this->notch = $notch;

        return $this->invalidatePaint();
    }

    public function springs(): array
    {
        return [...array_values($this->hover), ...array_values($this->press), $this->glyphs];
    }

    /** Lights at the left (the system's side on macOS, or the one set). */
    public function lights(): bool
    {
        return ($this->side ?? (PHP_OS_FAMILY === 'Darwin' ? Align::Start : Align::End)) === Align::Start;
    }

    /** The buttons shown, left to right (header_bar.c:82-102): those the window offers and setButtons allows. @return list<WindowAction> */
    public function buttons(): array
    {
        $offered = $this->tree()?->windowActions()?->windowActions() ?? [];

        return array_values(array_filter($this->lights() ? self::LEFT : self::RIGHT,
            fn ($a) => $this->allowed[$a->value] && in_array($a, $offered, true)));
    }

    /** Where a button is drawn (header_bar.c:136-154); null when not shown. */
    public function buttonRect(WindowAction $a): ?Rect
    {
        $buttons = $this->buttons();
        $at = array_search($a, $buttons, true);
        if ($at === false) {
            return null;
        }
        $m = $this->metrics();
        $b = $this->bounds();
        $count = count($buttons);
        $x = $this->lights() ? $b->x + $m['margin'] : $b->x + $b->w - $m['margin'] - $m['size'] * $count - $m['gap'] * ($count - 1);
        $x += ($m['size'] + $m['gap']) * $at;

        return $m['variant'] === 'flat'
            ? new Rect($x, $b->y, $m['size'], $b->h)
            : new Rect($x, $b->y + floor(($b->h - $m['size']) / 2), $m['size'], $m['size']);
    }

    /** Small round buttons are easier to hit a little outside their circle (header_bar.c:156-166). */
    public function buttonAt(float $x, float $y): ?WindowAction
    {
        $slack = $this->metrics()['variant'] === 'flat' ? 0.0 : 3.0;
        foreach ($this->buttons() as $a) {
            $r = $this->buttonRect($a);
            if ($x >= $r->x - $slack && $x < $r->x + $r->w + $slack && $y >= $r->y - $slack && $y < $r->y + $r->h + $slack) {
                return $a;
            }
        }

        return null;
    }

    /** Draggable on the bar, Normal on a child, a button, and outside the bar. */
    public function hitArea(float $x, float $y): HitArea
    {
        if (! $this->bounds()->contains($x, $y) || $this->buttonAt($x, $y) !== null) {
            return HitArea::Normal;
        }
        foreach ($this->visibleChildren() as $child) {
            if ($child->bounds()->contains($x, $y)) {
                return HitArea::Normal;
            }
        }

        return HitArea::Draggable;
    }

    /** The buttons' look: 'lights' (macOS side), else the HeaderBar Buttons part's variant (flat, round) with its size, gap and margin. @return array{variant: string, size: float, gap: float, margin: float} */
    private function metrics(): array
    {
        // header_bar.c:104-108: glass buttons are 28 wide, 6 apart, 10 from the edge
        if (! $this->lights() && $this->look()->material()->isGlass()) {
            return ['variant' => 'glass', 'size' => 28.0, 'gap' => 6.0, 'margin' => 10.0];
        }
        $part = $this->look()->part('HeaderBar', $this->lights() ? 'Lights' : 'Buttons', State::Normal)->extra;

        return ['variant' => $this->lights() ? 'lights' : (string) ($part['variant'] ?? 'flat'), 'size' => (float) ($part['size'] ?? 46.0),
            'gap' => (float) ($part['gap'] ?? 0.0), 'margin' => (float) ($part['margin'] ?? 0.0)];
    }

    private function buttonsWidth(): float
    {
        $count = count($this->buttons());
        if ($count === 0) {
            return 0.0;
        }
        $m = $this->metrics();

        return $m['margin'] + $m['size'] * $count + $m['gap'] * ($count - 1) + ($m['margin'] > 0 ? 4.0 : 0.0);
    }

    /** header_bar.c:182-198 */
    protected function measureContent(Size $available): Size
    {
        $w = self::PADDING * 2 + $this->buttonsWidth();
        $h = $this->look()->metric(Metric::ControlHeight) + (float) ($this->look()->part('HeaderBar', 'Body', State::Normal)->extra['extraHeight'] ?? 10.0);
        foreach ($this->visibleChildren() as $child) {
            $s = $child->measure($available);
            $w += $s->w + self::SPACING;
            $h = max($h, $s->h + 8);
        }
        $title = $this->title();
        $title === '' || $w += $this->textSize($title)->w + 24;

        return new Size(min($available->w, $w), $h);
    }

    /** Children from the left, those at Align::End from the right, grow sharing what is left (header_bar.c:200-250). */
    protected function arrangeContent(Rect $r): void
    {
        $buttons = $this->buttonsWidth();
        $x0 = $r->x + self::PADDING + ($this->lights() ? $buttons : 0.0);
        $x1 = $r->x + $r->w - self::PADDING - ($this->lights() ? 0.0 : $buttons);
        $children = $this->visibleChildren();
        [$fixed, $grow] = [0.0, 0.0];
        $sizes = [];
        foreach ($children as $i => $c) {
            $sizes[$i] = $c->measure(new Size($r->w, $r->h));
            $slot = $this->slot($c);
            $slot->grow > 0 ? $grow += $slot->grow : $fixed += $sizes[$i]->w;
        }
        count($children) > 1 && $fixed += self::SPACING * (count($children) - 1);
        $remaining = max(0.0, $x1 - $x0 - $fixed);
        $rightWidth = 0.0;
        for ($pass = 0; $pass < 2; $pass++) {
            $x = $pass === 0 ? $x0 : $x1 - $rightWidth;
            foreach ($children as $i => $c) {
                $slot = $this->slot($c);
                $atRight = $slot->align === Align::End;
                $w = $slot->grow > 0 ? ($grow > 0 ? $remaining * $slot->grow / $grow : 0.0) : $sizes[$i]->w;
                if ($atRight !== ($pass === 1)) {
                    $pass === 0 && $rightWidth += $w + self::SPACING;
                    continue;
                }
                // a control keeps its height and sits in the middle of the bar
                $h = min($sizes[$i]->h, $r->h);
                $c->arrange(new Rect($x, $r->y + floor(($r->h - $h) / 2), $w, $h));
                $x += $w + self::SPACING;
            }
            if ($pass === 0) {
                $this->gapX0 = $x;
                $rightWidth > 0 && $rightWidth -= self::SPACING;
                $this->gapX1 = $x1 - $rightWidth - ($rightWidth > 0 ? self::SPACING : 0.0);
            }
        }
    }

    public function handle(SilkEvent $e): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }
        $at = $this->buttonAt($e->x, $e->y);
        switch ($e->type) {
            case EventType::MouseMove:
                $this->setHot($at);

                return $this->down !== null;
            case EventType::Leave:
                $this->down === null && $this->setHot(null);

                return false;
            case EventType::MouseDown:
                if ($e->button !== MouseButton::LEFT || $at === null) {
                    return false;
                }
                [$this->down, $this->hot] = [$at, $at];
                $this->invalidatePaint();

                return true;
            case EventType::MouseUp:
                $down = $this->down;
                if ($e->button !== MouseButton::LEFT || $down === null) {
                    return false;
                }
                $this->down = null;
                $this->setHot($at);
                $at === $down && $this->trigger($down);

                return true;
            default:
                return false;
        }
    }

    /** header_bar.c:470-487: the button springs back past its size, then the window acts. */
    private function trigger(WindowAction $a): void
    {
        $this->hover[$a->value]->kick(6.0);
        $this->post(new ButtonClicked($this->window(), $this->path().'.'.$a->value, $this->uuid()));
        $this->tree()?->windowActions()?->performWindowAction($a);
    }

    private function setHot(?WindowAction $a): void
    {
        if ($a !== $this->hot) {
            $this->hot = $a;
            $this->invalidatePaint();
        }
    }

    protected function paintSelf(Painter $p): void
    {
        $look = $this->look();
        $b = $this->bounds();
        $active = $this->tree()?->windowActions()?->isActive() ?? true;
        if (! $this->noBackground && ($look->material()->isGlass() || $look->liquid())) {
            // header_bar.c:359-364: the bar is glass over the content, with no line under it
            Surfaces::paint($p, $look, $b, 0.0, SurfaceKind::Bar);
        } elseif (! $this->noBackground) {
            $body = $look->part('HeaderBar', 'Body', State::Normal);
            $body->background && $p->rect($b, $body->background);
            $line = $look->part('HeaderBar', 'Line', State::Normal);
            $line->border && $p->rect(new Rect($b->x, $b->y + $b->h - 1, $b->w, 1), $line->border);
        }
        $this->paintTitle($p, $active);
        foreach (WindowAction::cases() as $a) {
            $this->hover[$a->value]->target($this->hot === $a && $this->isEnabled() ? 1.0 : 0.0);
            $this->press[$a->value]->target($this->down === $a && $this->hot === $a ? 1.0 : 0.0);
        }
        $this->lights() && $this->glyphs->target($this->hot !== null ? 1.0 : 0.0);
        foreach ($this->buttons() as $a) {
            $this->paintButton($p, $a, $active);
        }
    }

    /** header_bar.c:419-460 */
    private function paintTitle(Painter $p, bool $active): void
    {
        $title = $this->title();
        if ($title === '' || $this->gapX1 - $this->gapX0 <= 24) {
            return;
        }
        $look = $this->look();
        $b = $this->bounds();
        $style = $this->textStyle();
        $color = $this->color($this->isEnabled() ? Role::Text : Role::TextDisabled, $look);
        $width = $this->textSize($title, $style)->w;
        $gap = $this->gapX1 - $this->gapX0 - 16;
        if ($this->titleLeft) {
            $x = $this->gapX0 + 8;
        } else {
            // in the middle of the window when it fits there, else in the middle of the room left
            $room = $this->notch ? 30.0 : 8.0;
            $x = $b->x + floor(($b->w - $width) / 2);
            if ($x < $this->gapX0 + $room || $x + $width > $this->gapX1 - $room) {
                $x = $this->gapX0 + 8 + max(0.0, floor(($gap - $width) / 2));
            }
        }
        $p->pushClip(new Rect($this->gapX0 + 8, $b->y, $gap, $b->h));
        if ($this->notch) {
            // a dark tab hanging from the top edge: slanted sides, round bottom corners
            $n = $look->part('HeaderBar', 'Notch', $active ? State::Normal : State::Disabled);
            [$pad, $slant, $radius] = [18.0, 9.0, 7.0];
            $height = max(16.0, min($b->h - 5, $look->metric(Metric::ControlHeight) + 4));
            [$nx, $nw] = [$x - $pad, $width + $pad * 2];
            $fill = self::mix($this->color(Role::Header, $look), $n->background, (float) ($n->extra['mix'] ?? 0.9));
            $n->shadowColor && $p->shadow(new Rect($nx, $b->y - $radius, $nw, $height + $radius), $radius, 8.0, $n->shadowColor);
            $p->rect(new Rect($nx, $b->y - 1, $nw, $height + 1), $fill, [0.0, 0.0, $radius, $radius]);
            $p->triangle($nx - $slant, $b->y - 1, $nx + 0.5, $b->y - 1, $nx + 0.5, $b->y + $height - $radius, $fill);
            $p->triangle($nx + $nw - 0.5, $b->y - 1, $nx + $nw + $slant, $b->y - 1, $nx + $nw - 0.5, $b->y + $height - $radius, $fill);
            $n->border && $p->rect(new Rect($nx + $radius, $b->y + $height - 1, $nw - $radius * 2, 1), $n->border);
            $tab = new Rect($x, $b->y, $width + 2, $height);
            $p->text($title, $x, $this->baselineIn($tab, $style), $n->text ?? $color, $style);
        } else {
            $p->text($title, $x, $this->baselineIn($b, $style), $active ? $color : self::fade($color, 0.5), $style);
        }
        $p->popClip();
    }

    /** header_bar.c:285-389 */
    private function paintButton(Painter $p, WindowAction $a, bool $active): void
    {
        $look = $this->look();
        $r = $this->buttonRect($a);
        $m = $this->metrics();
        $hover = max(0.0, min(1.0, $this->hover[$a->value]->value()));
        $press = max(0.0, min(1.0, $this->press[$a->value]->value()));
        $text = $this->color(Role::Text, $look);
        $maximized = $this->tree()?->windowActions()?->isMaximized() ?? false;
        [$cx, $cy] = [$r->x + $r->w / 2, $r->y + $r->h / 2];
        $scale = 1.0;
        if ($m['variant'] === 'lights') {
            // three lights: red, yellow, green; grey while the window is in the background
            $light = $look->part('HeaderBar', ucfirst($a->value).'Light', State::Normal);
            $p->rect($r, $active ? $light->background : self::fade($text, 0.22), $r->w / 2, $light->border, $light->borderWidth);
            $g = max(0.0, min(1.0, $this->glyphs->value()));
            $scale = 1.0 + 0.26 * $this->hover[$a->value]->value() - 0.2 * $press;
            if ($g > 0.02) {
                $glyph = $look->part('HeaderBar', 'LightGlyph', State::Normal);
                $this->transformed($p, $cx, $cy, $scale, fn () => $this->glyph($p, $a, $maximized, true, $cx, $cy, $a === WindowAction::Close ? 5.0 : 6.5,
                    self::fade($glyph->text ?? $text, $g)));
            }

            return;
        }
        if ($m['variant'] === 'glass') {
            // header_bar.c:371-378: a bead of glass; the one that closes turns red under the pointer
            Surfaces::paint($p, $look, $r, $r->w / 2, SurfaceKind::Control, $hover, $press);
            if ($a === WindowAction::Close && $hover > 0.01) {
                $p->rect($r, self::fade($this->color(Role::Danger, $look), 0.88 * $hover), $r->w / 2);
                $text = self::mix($text, new Color(1, 1, 1), $hover);
            }
            $scale = 1.0 + 0.14 * $this->hover[$a->value]->value() - 0.16 * $press;
            $this->transformed($p, $cx, $cy, $scale, fn () => $this->glyph($p, $a, $maximized, false, $cx, $cy, 9.0, $text));

            return;
        }
        $part = $look->part('HeaderBar', $a === WindowAction::Close ? 'CloseButton' : 'CaptionButton',
            $press > 0.5 ? State::Pressed : ($hover > 0.5 ? State::Hover : State::Normal));
        if ($m['variant'] === 'round') {
            // GNOME: round buttons, their symbolic icons
            $p->pushOpacity($active ? 1.0 : 0.6);
            $p->rect($r, $part->background ?? $this->color(Role::Control, $look), $r->w / 2);
            $this->glyph($p, $a, $maximized, false, $cx, $cy, 8.0, $part->text ?? $text);
            $p->popOpacity();

            return;
        }
        // flat (the Raven look): the button is a part of the bar; only the glyph moves
        $normal = $look->part('HeaderBar', $a === WindowAction::Close ? 'CloseButton' : 'CaptionButton', State::Normal);
        $hovered = $look->part('HeaderBar', $a === WindowAction::Close ? 'CloseButton' : 'CaptionButton', State::Hover);
        $pressed = $look->part('HeaderBar', $a === WindowAction::Close ? 'CloseButton' : 'CaptionButton', State::Pressed);
        $fill = self::mix(self::fade($hovered->background ?? $this->color(Role::ControlHovered, $look), $hover), $pressed->background ?? $this->color(Role::ControlPressed, $look), $press);
        $p->rect($r, $fill);
        $ink = $hovered->text !== null ? self::mix($text, $hovered->text, $hover) : $text;
        $scale = 1.0 + 0.22 * $this->hover[$a->value]->value() - 0.28 * $press;
        $this->transformed($p, $cx, $cy, $scale, fn () => $this->glyph($p, $a, $maximized, false, $cx, $cy, 10.0, $active ? $ink : self::fade($ink, 0.55)));
    }

    private function transformed(Painter $p, float $cx, float $cy, float $s, \Closure $draw): void
    {
        $moved = abs($s - 1.0) > 0.001;
        $moved && $p->pushTransform($s, $cx * (1 - $s), $cy * (1 - $s));
        $draw();
        $moved && $p->popTransform();
    }

    /** header_bar.c:256-283 */
    private function glyph(Painter $p, WindowAction $a, bool $maximized, bool $light, float $cx, float $cy, float $size, Color $c): void
    {
        $half = $size / 2;
        $t = $light ? 1.3 : 1.1;
        $none = new Color(0, 0, 0, 0);
        match (true) {
            $a === WindowAction::Close => $p->line($cx - $half, $cy - $half, $cx + $half, $cy + $half, $c, $t)->line($cx - $half, $cy + $half, $cx + $half, $cy - $half, $c, $t),
            $a === WindowAction::Minimize => $p->line($cx - $half, $cy, $cx + $half, $cy, $c, $t),
            // a plus, as on the green light
            $light => $p->line($cx - $half, $cy, $cx + $half, $cy, $c, $t)->line($cx, $cy - $half, $cx, $cy + $half, $c, $t),
            // two windows one behind the other: "restore"
            $maximized => $p->rect(new Rect($cx - $half + $size * 0.22, $cy - $half, $size * 0.78, $size * 0.78), $none, 1.5, $c, $t)
                ->rect(new Rect($cx - $half, $cy - $half + $size * 0.22, $size * 0.78, $size * 0.78), $none, 1.5, $c, $t),
            default => $p->rect(new Rect($cx - $half, $cy - $half, $size, $size), $none, 1.5, $c, $t),
        };
    }

    private static function fade(Color $c, float $a): Color
    {
        return new Color($c->red, $c->green, $c->blue, $c->alpha * $a);
    }

    private static function mix(?Color $a, ?Color $b, float $t): Color
    {
        $a ??= new Color(0, 0, 0, 0);
        $b ??= $a;

        return new Color($a->red + ($b->red - $a->red) * $t, $a->green + ($b->green - $a->green) * $t,
            $a->blue + ($b->blue - $a->blue) * $t, $a->alpha + ($b->alpha - $a->alpha) * $t);
    }
}
