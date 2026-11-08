<?php

namespace Silk\Widgets;

use Closure;
use InvalidArgumentException;
use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\PartLook;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\State;
use Silk\Contracts\Look\SurfaceKind;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Motion\LookBlend;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Silk\Core\Motion\Transition;
use Silk\Core\Tree\Container;
use Silk\Contracts\Mail\TabClosed;
use Silk\Painter\Icons;
use Silk\Painter\PartBox;
use Silk\Painter\RavenPaint;
use Silk\Painter\Surfaces;
use Silk\Widgets\Concerns\MeasuresText;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Mail\View\TabSelected;
use Surface\NutsAndBolts\Color;
use Surface\Contracts\Framebuffers\Framebuffer;

/**
 * `tabView(name)` as TKTabView; Raven widgets/tab_view.c. Pages, one column each, behind titled tabs; the first page
 * added is selected. The active tab's mark slides on BOUNCY, stretched by its speed; a page chosen comes in from the
 * side of its tab unless it has an appear transition of its own. A click or Ctrl-Tab (Ctrl-Shift-Tab back) selects
 * and posts TabSelected; select() posts nothing.
 */
class TabView extends Container
{
    use MeasuresText;

    /** @var list<string> */
    private array $order = [];

    /** @var array<string, string> */
    private array $titles = [];

    /** @var array<string, Column> */
    private array $pages = [];

    private ?string $selected = null;

    /** The left edge and the width of the mark of the active tab (tab_view.c:21-23). */
    private Spring $markX;

    private Spring $markW;

    private bool $placed = false;

    private ?string $hot = null;

    /** Raven's documents mode (tab_view.c:5-10): tabs on the left, icons, close buttons, shrinking. */
    private bool $documents = false;

    /** @var array<string, array{Framebuffer, bool}> a page's icon, and whether it takes the title's colour */
    private array $icons = [];

    /** @var array<string, true> pages without a close button (Raven slot dock_fixed) */
    private array $fixed = [];

    /** fn (string $page): bool, false keeps the tab (Raven RTabCloseCallback) */
    private ?Closure $closeCallback = null;

    private bool $hotClose = false;

    private ?string $pressClose = null;

    private const float ICON = 16.0;

    private const float ICON_GAP = 6.0;

    private const float CLOSE = 18.0;

    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->focusable = true;
        $this->markX = new Spring(0.0, SpringPreset::Bouncy);
        $this->markW = new Spring(0.0, SpringPreset::Bouncy);
    }

    /** A page before the one at $index; null appends. Posts no TabSelected. */
    public function addPage(string $name, string $title, ?int $index = null): Column
    {
        $page = new Column($name, 6, 10);
        $this->add($page);
        array_splice($this->order, $index ?? count($this->order), 0, [$name]);
        $this->titles[$name] = $title;
        $this->pages[$name] = $page;
        $this->selected === null ? $this->selected = $name : $page->setVisible(false);

        return $page;
    }

    /** Raven's documents mode (Rtab_view_set_documents); TK's tab view has none, so this is Silk's own. */
    public function setDocuments(bool $documents): static
    {
        $this->documents = $documents;

        return $this->invalidateLayout();
    }

    public function isDocuments(): bool
    {
        return $this->documents;
    }

    /**
     * A tab's icon, 16 square before its title (documents; Rtab_view_set_tab_icon). $tint paints it in the title's colour.
     * Raven's u0..v1 atlas rect is not taken: blocked, Surface image() has no source rect (roadmap Blocked list).
     */
    public function setTabIcon(string $page, ?Framebuffer $icon, bool $tint = false): static
    {
        $this->page($page);
        if ($icon === null) {
            unset($this->icons[$page]);
        } else {
            $this->icons[$page] = [$icon, $tint];
        }

        return $this->invalidateLayout();
    }

    /** Whether a documents tab has a close button; a fixed tab has none (Rtab_view_set_tab_closable). */
    public function setTabClosable(string $page, bool $closable): static
    {
        $this->page($page);
        if ($closable) {
            unset($this->fixed[$page]);
        } else {
            $this->fixed[$page] = true;
        }

        return $this->invalidateLayout();
    }

    public function isTabClosable(string $page): bool
    {
        return $this->documents && ! isset($this->fixed[$this->page($page)->name()]);
    }

    /** Asked before a tab closes; false keeps it (Rtab_view_set_close_callback). */
    public function setCloseCallback(?Closure $callback): static
    {
        $this->closeCallback = $callback;

        return $this;
    }

    /** Removes a page; the page shown stays shown, or the one now at its place is (tab_view.c:126-142). Posts nothing. */
    public function removePage(string $name): static
    {
        $page = $this->page($name);
        $at = (int) array_search($name, $this->order, true);
        $this->remove($page);
        array_splice($this->order, $at, 1);
        unset($this->titles[$name], $this->pages[$name], $this->icons[$name], $this->fixed[$name]);
        [$this->hot, $this->pressClose] = [null, null];
        if ($this->selected === $name) {
            $this->selected = null;
            if ($this->order !== []) {
                $next = $this->order[min($at, count($this->order) - 1)];
                $this->pages[$next]->setVisible(true);
                $this->selected = $next;
            }
        }

        return $this->invalidateLayout();
    }

    /** The user closes a tab: the callback may keep it, else the page goes and TabClosed is posted (tab_view.c:162-173). */
    private function requestClose(string $page): void
    {
        if (! $this->isTabClosable($page) || ($this->closeCallback !== null && ! ($this->closeCallback)($page))) {
            return;
        }
        $this->removePage($page);
        $this->post(new TabClosed($this->window(), $this->path(), $this->uuid(), $page));
    }

    /** @return list<string> */
    public function pages(): array
    {
        return $this->order;
    }

    public function page(string $name): Column
    {
        return $this->pages[$name] ?? throw new InvalidArgumentException("TabView '{$this->name}' has no page '{$name}'.");
    }

    public function setTitle(string $page, string $title): static
    {
        $this->page($page);
        $this->titles[$page] = $title;

        return $this->invalidateLayout();
    }

    public function title(string $page): string
    {
        $this->page($page);

        return $this->titles[$page];
    }

    public function selected(): ?string
    {
        return $this->selected;
    }

    /** Posts nothing: the app changed it, it knows. */
    public function select(string $page): static
    {
        $this->page($page);
        if ($page === $this->selected) {
            return $this;
        }
        $from = array_search($this->selected, $this->order, true);
        foreach ($this->pages as $name => $p) {
            if ($name !== $page) {
                $p->setVisible(false);
            } elseif ($p->appearTransition() === null && $from !== false && ! $this->documents) {
                // the new page comes from the side its tab is on (tab_view.c:180-184)
                $side = array_search($page, $this->order, true) > $from ? Transition::SlideLeft : Transition::SlideRight;
                $p->appear($side)->setVisible(true)->appear(null);
            } else {
                $p->setVisible(true);
            }
        }
        $this->selected = $page;
        $this->placed && $this->aimMark();

        return $this->invalidateLayout();
    }

    /** The mark's left edge, as drawn. */
    public function indicator(): float
    {
        return $this->markX->value();
    }

    public function springs(): array
    {
        return [$this->markX, $this->markW];
    }

    /** A title, the padding either side, and 4; documents add the icon and the close button, 96 to 280 (tab_view.c:61-69). */
    private function naturalWidth(string $page): float
    {
        $w = $this->textSize($this->titles[$page])->w + 2 * $this->look()->metric(Metric::PaddingX) + 4;
        if (! $this->documents) {
            return $w;
        }
        isset($this->icons[$page]) && $w += self::ICON + self::ICON_GAP;
        $this->isTabClosable($page) && $w += self::CLOSE + 4;

        return min(280.0, max(96.0, $w + 8));
    }

    /** Documents: the tabs shrink when they do not fit, to 48 at least (tab_view.c:71-81). */
    private function tabWidth(string $page): float
    {
        $w = $this->naturalWidth($page);
        $room = $this->bounds()->w;
        if ($this->documents && $room > 0) {
            $total = array_sum(array_map(fn ($n) => $this->naturalWidth($n), $this->order));
            $total > $room - 8 && $w = max(48.0, $w * ($room - 8) / $total);
        }

        return $w;
    }

    public function tabRect(string $page): Rect
    {
        $b = $this->bounds();
        $x = $b->x + ($this->documents ? 4 : 0);
        foreach ($this->order as $name) {
            if ($name === $page) {
                return new Rect($x, $b->y, $this->tabWidth($name), $this->strip());
            }
            $x += $this->tabWidth($name);
        }
        throw new InvalidArgumentException("TabView '{$this->name}' has no page '{$page}'.");
    }

    /** ControlHeight and 2 (8 in a glass look, which keeps some air under its segmented control); documents ControlHeight and 10 (tab_view.c:47-51). */
    private function strip(): float
    {
        return $this->look()->metric(Metric::ControlHeight) + ($this->documents ? 10 : ($this->look()->material()->isGlass() ? 8 : 2));
    }

    /** A documents tab's close button (tab_view.c:83-86). */
    public function closeRect(string $page): Rect
    {
        $t = $this->tabRect($page);

        return new Rect($t->x + $t->w - self::CLOSE - 6, $this->bounds()->y + ($this->strip() - self::CLOSE) / 2 + 1, self::CLOSE, self::CLOSE);
    }

    protected function measureContent(Size $available): Size
    {
        // documents: the major tabs fill the window, they do not grow with their pages (tab_view.c:98-100)
        if ($this->documents) {
            return new Size(0, $this->strip() + 40);
        }
        $w = 0.0;
        foreach ($this->order as $name) {
            $w += $this->tabWidth($name);
        }
        $page = $this->selected === null ? new Size(0, 0)
            : $this->pages[$this->selected]->measure(new Size($available->w, max(0.0, $available->h - $this->strip())));

        return new Size(max($w, $page->w), $this->strip() + $page->h);
    }

    protected function arrangeContent(Rect $r): void
    {
        if ($this->selected === null) {
            return;
        }
        $this->pages[$this->selected]->arrange(new Rect($r->x, $r->y + $this->strip(), $r->w, max(0.0, $r->h - $this->strip())));
        $this->aimMark(! $this->placed);
        $this->placed = true;
    }

    private function aimMark(bool $snap = false): void
    {
        $t = $this->tabRect($this->selected);
        $snap ? $this->markX->snap($t->x) : $this->markX->target($t->x);
        $snap ? $this->markW->snap($t->w) : $this->markW->target($t->w);
    }

    /** A user's choice: selects and posts TabSelected. */
    private function choose(string $page): void
    {
        if ($page !== $this->selected) {
            $this->select($page);
            $this->post(new TabSelected($this->window(), $this->path(), $this->uuid(), $page));
        }
    }

    private function tabAt(float $x, float $y): ?string
    {
        foreach ($this->order as $name) {
            if ($this->tabRect($name)->contains($x, $y)) {
                return $name;
            }
        }

        return null;
    }

    public function handle(SilkEvent $e): bool
    {
        if (! $this->isEnabled() || $this->order === []) {
            return false;
        }
        if ($e->type === EventType::MouseMove || $e->type === EventType::Leave) {
            $hot = $e->type === EventType::Leave ? null : $this->tabAt($e->x, $e->y);
            $onClose = $hot !== null && $this->onClose($hot, $e->x, $e->y);
            if ($hot !== $this->hot || $onClose !== $this->hotClose) {
                [$this->hot, $this->hotClose] = [$hot, $onClose];
                $this->invalidatePaint();
            }

            return false;
        }
        if ($e->type === EventType::MouseDown && ($tab = $this->tabAt($e->x, $e->y)) !== null) {
            // documents: a middle click closes (tab_view.c:219-222)
            if ($this->documents && $e->button === MouseButton::MIDDLE) {
                $this->requestClose($tab);

                return true;
            }
            if ($e->button !== MouseButton::LEFT) {
                return false;
            }
            if ($this->onClose($tab, $e->x, $e->y)) {
                $this->pressClose = $tab;
                $this->invalidatePaint();

                return true;
            }
            $this->choose($tab);

            return true;
        }
        if ($e->type === EventType::MouseUp && $this->pressClose !== null && $e->button === MouseButton::LEFT) {
            // the close button acts when released on it (tab_view.c:232-241)
            $pressed = $this->pressClose;
            $this->pressClose = null;
            $this->invalidatePaint();
            $this->tabAt($e->x, $e->y) === $pressed && $this->onClose($pressed, $e->x, $e->y) && $this->requestClose($pressed);

            return true;
        }
        if ($e->type === EventType::KeyDown && $e->key === Key::TAB && $e->modifiers->ctrl) {
            $n = count($this->order);
            $at = (int) array_search($this->selected, $this->order, true);
            $this->choose($this->order[($at + ($e->modifiers->shift ? -1 : 1) + $n) % $n]);

            return true;
        }

        return false;
    }

    private function onClose(string $page, float $x, float $y): bool
    {
        return $this->isTabClosable($page) && $this->closeRect($page)->contains($x, $y);
    }

    /** The mark where it is now, stretched by its own speed (tab_view.c:503-507). */
    private function mark(): array
    {
        $w = $this->markW->value();
        $stretch = min(abs($this->markX->velocity()) * 0.018, $w * 0.35);

        return [$this->markX->value() - $stretch / 2, $w + $stretch];
    }

    protected function paintSelf(Painter $p): void
    {
        if ($this->order === []) {
            return;
        }
        $segmented = ($this->look()->part('TabView', 'Body', State::Normal)->extra['paint'] ?? 'raven') === 'segmented';
        match (true) {
            $this->documents && $segmented => $this->paintDocumentsPlatform($p),
            $this->documents => $this->paintDocuments($p),
            $this->look()->material()->isGlass() => $this->paintGlass($p),
            $segmented => $this->paintSegmented($p),
            default => $this->paintRaven($p),
        };
    }

    /** A glass look's segmented control: a capsule holding the titles, the active one on a lighter pill of glass (tab_view.c:566-593). */
    private function paintGlass(Painter $p): void
    {
        $look = $this->look();
        $b = $this->bounds();
        $bar = $this->strip();
        $text = $this->color($this->isEnabled() ? Role::Text : Role::TextDisabled, $look);
        $total = array_sum(array_map(fn ($n) => $this->tabWidth($n), $this->order));
        if ($total > 0) {
            $p->rect(new Rect($b->x, $b->y + 2, $total, $bar - 6), $look->color(Role::Track), ($bar - 6) * 0.5);
        }
        [$mx, $mw] = $this->mark();
        if ($this->selected !== null && $mw > 4) {
            $pill = new Rect($mx + 2, $b->y + 4, $mw - 4, $bar - 10);
            Surfaces::paint($p, $look, $pill, $pill->h * 0.5, SurfaceKind::Control, 1.0, 0.6, $this->isEnabled());
        }
        foreach ($this->order as $name) {
            $t = $this->tabRect($name);
            $active = $name === $this->selected;
            $rect = new Rect($t->x, $b->y + 2, $t->w, $bar - 6);
            if (! $active && $name === $this->hot) {
                $pill = new Rect($rect->x + 2, $rect->y + 2, $rect->w - 4, $rect->h - 4);
                $p->rect($pill, $look->color(Role::HeaderHovered), $pill->h * 0.5);
            }
            $this->paintTitle($p, $name, $rect, $active ? $text : new Color($text->red, $text->green, $text->blue, $text->alpha * 0.62));
        }
    }

    /** Raven's own: a Header strip, the active tab TabActive with an Accent top, the others dimmed (tab_view.c:590-611). */
    private function paintRaven(Painter $p): void
    {
        $look = $this->look();
        $b = $this->bounds();
        $bar = $this->strip();
        $text = $this->color($this->isEnabled() ? Role::Text : Role::TextDisabled, $look);
        $p->rect(new Rect($b->x, $b->y, $b->w, $bar), $look->color(Role::Header));
        $p->rect(new Rect($b->x, $b->y + $bar - 1, $b->w, 1), $look->color(Role::Separator));
        [$mx, $mw] = $this->mark();
        if ($this->selected !== null && $mw > 0) {
            $p->rect(new Rect($mx, $b->y, $mw, $bar), $look->color(Role::TabActive));
            $p->rect(new Rect($mx, $b->y, $mw, 2), $look->color(Role::Accent));
        }
        foreach ($this->order as $name) {
            $t = $this->tabRect($name);
            $active = $name === $this->selected;
            if (! $active && $name === $this->hot) {
                $p->rect($t, $look->color(Role::HeaderHovered));
            }
            // (the active tab's ink reads on TabActive, which is the accent in the high-contrast looks)
            $this->paintTitle($p, $name, $t, $active ? RavenPaint::inkOn($text, $look->color(Role::TabActive), $look->color(Role::Header), $look->color(Role::AccentText))
                : self::mixColor($text, $look->color(Role::Header), (float) ($look->part('TabView', 'Body', State::Normal)->extra['dimMix'] ?? 0.3)));
        }
    }

    /** macOS and GNOME: a segmented control, its raised pill sliding to the active title (tab_view.c:376-412, 446-484). */
    private function paintSegmented(Painter $p): void
    {
        $look = $this->look();
        $b = $this->bounds();
        $bar = $this->strip();
        $style = $look->part('TabView', 'Body', State::Normal)->extra;
        $height = isset($style['height']) ? min((float) $style['height'], $bar - 2) : $bar - 2;
        $top = $b->y + floor(($bar - $height) / 2);
        $inset = (float) ($style['inset'] ?? 1.0);
        $total = array_sum(array_map(fn ($n) => $this->tabWidth($n), $this->order));
        $this->part($p, new Rect($b->x, $top, $total, $height), $look->part('TabView', 'Header', $this->isEnabled() ? State::Normal : State::Disabled));
        [$mx, $mw] = $this->mark();
        if ($this->selected !== null && $mw > 4) {
            $this->part($p, new Rect($mx + $inset, $top + $inset, $mw - 2 * $inset, $height - 2 * $inset), $look->part('TabView', 'Indicator', State::Selected));
        }
        $last = end($this->order);
        foreach ($this->order as $i => $name) {
            $t = $this->tabRect($name);
            $active = $name === $this->selected;
            $hot = $name === $this->hot && $this->isEnabled();
            $state = ! $this->isEnabled() ? State::Disabled : ($active ? State::Selected : ($hot ? State::Hover : State::Normal));
            // macOS: a hairline between two titles not next to the active one
            $divider = $look->part('TabView', 'Divider', State::Normal)->background;
            if ($divider !== null && $name !== $last && ! $active && ($this->order[$i + 1] ?? null) !== $this->selected) {
                $p->rect(new Rect($t->x + $t->w - 0.5, $top + 5, 1, $height - 10), $divider);
            }
            // GNOME: a faint box under a hot title
            $hover = $look->part('TabView', 'Hover', State::Normal);
            if (! $active && $hot && $hover->background !== null) {
                $p->rect(new Rect($t->x + $inset, $top + $inset, $t->w - 2 * $inset, $height - 2 * $inset), $hover->background, $hover->radius);
            }
            $this->paintTitle($p, $name, new Rect($t->x, $top, $t->w, $height), $look->part('TabView', 'Tab', $state)->text ?? $this->color(Role::Text, $look));
        }
    }

    /** Raven's documents: the major tabs, left aligned: icon, title, close button (tab_view.c:532-570). */
    private function paintDocuments(Painter $p): void
    {
        $look = $this->look();
        $b = $this->bounds();
        $bar = $this->strip();
        $text = $this->color($this->isEnabled() ? Role::Text : Role::TextDisabled, $look);
        $radius = min(6.0, $look->metric(Metric::CornerRadius) + 2);
        $top = [$radius, $radius, 0.0, 0.0];
        $p->rect(new Rect($b->x, $b->y, $b->w, $bar), $look->color(Role::Header));
        $p->rect(new Rect($b->x, $b->y + $bar - 1, $b->w, 1), $look->color(Role::Separator));
        foreach ($this->order as $i => $name) {
            $t = $this->tabRect($name);
            $active = $name === $this->selected;
            $hot = $name === $this->hot;
            $tab = new Rect($t->x, $b->y + 4, $t->w - 2, $bar - 4);
            $ink = $active ? RavenPaint::inkOn($text, $look->color(Role::TabActive), $look->color(Role::Header), $look->color(Role::AccentText)) : self::fade($text, $hot ? 0.85 : 0.6);
            if ($active) {
                $p->rect(new Rect($tab->x, $tab->y, $tab->w, $tab->h + 1), $look->color(Role::TabActive), $top);
                $p->rect(new Rect($tab->x, $tab->y, $tab->w, 2), $look->color(Role::Accent), $top);
            } elseif ($hot) {
                $p->rect($tab, $look->color(Role::HeaderHovered), $top);
            } elseif (isset($this->order[$i + 1]) && $this->order[$i + 1] !== $this->selected) {
                $p->rect(new Rect($tab->x + $tab->w + 0.5, $tab->y + 7, 1, $tab->h - 14), $look->color(Role::Separator));
            }
            $this->documentTab($p, $name, $tab, $tab->x + $look->metric(Metric::PaddingX), $ink, $active ? 1.0 : 0.75);
            if ($this->isTabClosable($name) && ($active || $hot)) {
                $c = $this->closeRect($name);
                $over = $hot && $this->hotClose;
                if ($over || $this->pressClose === $name) {
                    $p->rect($c, $look->color(Role::ControlPressed), self::CLOSE / 2);
                }
                Icons::paint($p, Icons::CROSS, $c->x + self::CLOSE / 2, $c->y + self::CLOSE / 2, 7.0, $active ? $ink : ($over ? $text : self::fade($text, 0.6)));
            }
        }
    }

    /** macOS and GNOME documents: tabs as rounded buttons, the active one ControlPressed (tab_view.c:398-445). */
    private function paintDocumentsPlatform(Painter $p): void
    {
        $look = $this->look();
        $b = $this->bounds();
        $bar = $this->strip();
        foreach ($this->order as $name) {
            $t = $this->tabRect($name);
            $active = $name === $this->selected;
            $hot = $name === $this->hot && $this->isEnabled();
            $state = ! $this->isEnabled() ? State::Disabled : ($active ? State::Selected : ($hot ? State::Hover : State::Normal));
            $part = $look->part('TabView', 'Tab', $state);
            $ink = $part->text ?? $this->color(Role::Text, $look);
            $tab = new Rect($t->x + 1, $b->y + 5, $t->w - 2, $bar - 10);
            $part->background !== null && $p->rect($tab, $part->background, $look->metric(Metric::CornerRadius) + (float) ($part->extra['radiusGrow'] ?? 0.0));
            $this->documentTab($p, $name, $tab, $tab->x + 10, $ink, 1.0);
            if ($this->isTabClosable($name) && ($active || $hot)) {
                $c = $this->closeRect($name);
                $over = $hot && $this->hotClose;
                $close = $look->part('TabView', 'Close', $this->pressClose === $name ? State::Pressed : State::Hover);
                if (($over || $this->pressClose === $name) && $close->background !== null) {
                    $p->rect($c, $close->background, min($close->radius, self::CLOSE / 2));
                }
                Icons::paint($p, Icons::CROSS, $c->x + self::CLOSE / 2, $c->y + self::CLOSE / 2, 7.0, $ink);
            }
        }
    }

    /** A documents tab's icon and title, clipped before its close button (tab_view.c:427-436, 548-558). */
    private function documentTab(Painter $p, string $name, Rect $tab, float $tx, Color $ink, float $iconAlpha): void
    {
        $p->pushClip(new Rect($tab->x, $tab->y, max(0.0, $tab->w - ($this->isTabClosable($name) ? self::CLOSE + 8 : 4)), $tab->h));
        if (isset($this->icons[$name])) {
            [$icon, $tint] = $this->icons[$name];
            // the tint's colour reaches the engine once Surface image() takes one (Blocked list #2); its alpha fades now
            $p->image($icon, new Rect($tx, floor($tab->y + ($tab->h - self::ICON) / 2), self::ICON, self::ICON), 0.0,
                $tint ? $ink : new Color(1, 1, 1, $iconAlpha));
            $tx += self::ICON + self::ICON_GAP;
        }
        $font = $this->textStyle();
        $p->text($this->titles[$name], $tx, $this->baselineIn($tab, $font), $ink, $font);
        $p->popClip();
    }

    private static function fade(Color $c, float $by): Color
    {
        return new Color($c->red, $c->green, $c->blue, $c->alpha * $by);
    }

    private function paintTitle(Painter $p, string $name, Rect $r, Color $ink): void
    {
        $font = $this->textStyle();
        $p->text($this->titles[$name], $r->x + ($r->w - $this->textSize($this->titles[$name])->w) / 2, $this->baselineIn($r, $font), $ink, $font);
    }

    /** rvn_paint_part: a shadow (the engine offsets it blur/4), the box, round as the rect allows. */
    private function part(Painter $p, Rect $r, PartLook $l): void
    {
        $radius = min($l->radius, min($r->w, $r->h) / 2);
        if ($l->shadow > 0 && $l->shadowColor !== null && $l->shadowColor->alpha > 0) {
            $p->shadow($r, $radius, $l->shadow, $l->shadowColor);
        }
        PartBox::paint($p, $r, $l->background, $radius, $l->border, $l->borderWidth, $l->borderTop, $l->borderBottom);
    }

    private static function mixColor(Color $a, Color $b, float $t): Color
    {
        return LookBlend::mix($a, $b, $t);
    }
}
