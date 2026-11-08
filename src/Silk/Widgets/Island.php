<?php

namespace Silk\Widgets;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Look;
use Silk\Contracts\Look\Material;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\State;
use Silk\Contracts\Look\SurfaceKind;
use Silk\Contracts\Mail\IslandChanged;
use Silk\Contracts\Painter\GlassLook;
use Silk\Contracts\Painter\Painter;
use Silk\Contracts\Widget\Widget as WidgetContract;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Silk\Core\Tree\Container;
use Silk\Core\Tree\LookScope;
use Silk\Looks\GlassStyle;
use Silk\Widgets\Concerns\Hoverable;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\NutsAndBolts\Color;

/**
 * `Island`: a small dark capsule, usually at the top of a window, that unfolds into a panel and folds back; Raven
 * widgets/island.c, raven_shell.h Risland_*. The first child shows folded, the second unfolded (a single child: an
 * empty capsule unfolding into it). The capsule goes from one size to the other on two springs (BOUNCY open, SMOOTH
 * shut) and paints both contents itself, each fading and scaling with how far it has gone, clipped inside it.
 * It is dark whatever the theme: its subtree paints with the look's dark twin, unless it was given a look of its own.
 * It stays unfolded while pinned (a click, setExpanded), held (expandFor) or under the pointer; else it folds, a
 * moment after the pointer left. A press elsewhere folds it. The user's changes post IslandChanged.
 */
class Island extends Container implements LookScope
{
    use Hoverable;

    /** Time the pointer may spend outside before an island it kept open folds back (island.c:35-36). */
    public const float LEAVE_DELAY = 0.28;

    private bool $expanded = false;

    private bool $noHover = false;

    private bool $noClick = false;

    private bool $attached = false;

    private bool $pinned = false;

    private bool $hovered = false;

    /** Seconds left of expandFor(); 0: not held. */
    private float $holdLeft = 0.0;

    /** Seconds since the pointer left; null: it has not. */
    private ?float $leftFor = null;

    /** Folded by a click under the pointer: the pointer does not unfold it again until it has left. */
    private bool $hoverBlocked = false;

    private Spring $width;

    private Spring $height;

    private bool $sized = false;

    /** @var array{float, float} the springs' values at the last measure */
    private array $measuredAt = [0.0, 0.0];

    private Size $folded;

    private Size $unfolded;

    private bool $ticking = false;

    private bool $watching = false;

    private bool $notchWanted = false;

    /** 'twin' (the dark twin of the window's look), 'window' (the window's own), or a Look of its own. */
    private Look|string $theme = 'twin';

    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->width = new Spring(0.0, SpringPreset::Bouncy);
        $this->height = new Spring(0.0, SpringPreset::Bouncy);
        $this->folded = $this->unfolded = new Size(0, 0);
    }

    // ---------------------------------------------------------------- API (raven_shell.h:293-335)

    /** Pinned open, or folded at once (Risland_set_expanded); posts nothing. */
    public function setExpanded(bool $expanded): static
    {
        if ($expanded) {
            [$this->pinned, $this->hoverBlocked] = [true, false];
            $this->update(false);
        } else {
            $this->fold(false);
        }

        return $this;
    }

    public function isExpanded(): bool
    {
        return $this->expanded;
    }

    /** Unfolded for $seconds; then it folds a moment after the pointer left, or at once if it is not there. */
    public function expandFor(float $seconds): static
    {
        [$this->holdLeft, $this->hoverBlocked, $this->leftFor] = [max(0.0, $seconds), false, null];
        $this->update(false);

        return $this;
    }

    public function setExpandOnHover(bool $enabled): static
    {
        $this->noHover = ! $enabled;

        return $this;
    }

    public function setExpandOnClick(bool $enabled): static
    {
        $this->noClick = ! $enabled;

        return $this;
    }

    /** Glued to the edge it hangs from: square corners there (Risland_set_attached). */
    public function setAttached(bool $attached): static
    {
        $this->attached = $attached;

        return $this->invalidatePaint();
    }

    /**
     * The camera housing of the screen (Risland_set_notch): recorded, but the island never goes there. blocked: Surface
     * has no screen housing geometry and no screen-anchored overlay window (roadmap Blocked list #11).
     */
    public function setNotch(bool $enabled): static
    {
        $this->notchWanted = $enabled;

        return $this;
    }

    public function notch(): bool
    {
        return $this->notchWanted;
    }

    /** Never, while the housing is blocked (roadmap #11). */
    public function isInNotch(): bool
    {
        return false;
    }

    /** Raven's Theme attribute: null the dark twin (default), 'window' the window's own look, or a Look of its own. */
    public function setTheme(Look|string|null $theme): static
    {
        $this->theme = $theme ?? 'twin';

        return $this->invalidateLayout();
    }

    public function scopedLook(Look $treeLook): ?Look
    {
        return match (true) {
            $this->theme instanceof Look => $this->theme,
            $this->theme === 'window' => null,
            default => $treeLook->darkTwin(),
        };
    }

    public function add(WidgetContract $child, ?\Silk\Contracts\Widget\Slot $slot = null): static
    {
        parent::add($child, $slot);
        $this->showActive();

        return $this;
    }

    // ---------------------------------------------------------------- what keeps it unfolded (island.c:133-237)

    /** The content shown folded (false) or unfolded (true). */
    private function content(bool $expanded): ?WidgetContract
    {
        $c = $this->children();
        if (count($c) === 1) {
            return $expanded ? $c[0] : null;
        }

        return $c[$expanded ? 1 : 0] ?? null;
    }

    private function set(bool $expanded, bool $notify): void
    {
        if ($expanded === $this->expanded) {
            return;
        }
        $this->expanded = $expanded;
        if ($expanded && ! $this->watching && ($tree = $this->tree()) !== null) {
            // a press anywhere else puts it away, as it closes a menu (rvn_islands_pressed)
            $this->watching = true;
            $tree->watchPresses(fn (?WidgetContract $hit, bool $onPopup) => $this->pressedAt($hit, $onPopup));
        }
        $this->showActive();
        $this->aimHover();
        $this->invalidateLayout();
        $notify && $this->post(new IslandChanged($this->window(), $this->path(), $this->uuid(), $expanded));
    }

    private function pressedAt(?WidgetContract $hit, bool $onPopup): bool
    {
        if (! $this->expanded) {
            return $this->watching = false;
        }
        if (! $onPopup && ! self::inside($hit, $this)) {
            $this->fold(true);

            return $this->watching = false;
        }

        return true;
    }

    private static function inside(?WidgetContract $w, WidgetContract $of): bool
    {
        for (; $w !== null; $w = $w->parent()) {
            if ($w === $of) {
                return true;
            }
        }

        return false;
    }

    /** Only the content shown takes the pointer; both still paint while the capsule changes. */
    private function showActive(): void
    {
        $active = $this->content($this->expanded);
        foreach ($this->children() as $c) {
            $c->isVisible() !== ($c === $active) && $c->setVisible($c === $active);
        }
    }

    /** The swell of a folded island under the pointer (island.c:394, rvn_widget_hover). */
    private function aimHover(): void
    {
        $this->hover()->target($this->hovered && ! $this->expanded && $this->isEnabled() ? 1.0 : 0.0);
        $this->invalidatePaint();
    }

    /** Unfolds, folds, or waits (island.c:176-215). */
    private function update(bool $notify): void
    {
        $this->hovered || $this->hoverBlocked = false;
        $held = $this->holdLeft > 0.0;
        // the pointer unfolds it when it may; once unfolded, it always keeps it so
        $hovered = $this->hovered && ! $this->hoverBlocked && (! $this->noHover || $this->expanded);
        if ($this->pinned || $held || $hovered) {
            $this->set(true, $notify);
            $held && ! $this->pinned && ! $hovered && $this->wait();
        } elseif ($this->expanded) {
            // nothing keeps it: it folds, a moment after the pointer left
            if ($this->leftFor === null || $this->leftFor + 0.002 >= self::LEAVE_DELAY) {
                $this->leftFor = null;
                $this->set(false, $notify);
            } else {
                $this->wait();
            }
        }
    }

    /** Time passes on the tree's frames until nothing is left to wait for. */
    private function wait(): void
    {
        if ($this->ticking || ($tree = $this->tree()) === null) {
            return;
        }
        $this->ticking = true;
        $tree->tick(function (float $dt): bool {
            $this->holdLeft = max(0.0, $this->holdLeft - $dt);
            $this->leftFor !== null && $this->leftFor += $dt;
            if ($this->holdLeft <= 0 && ($this->leftFor === null || $this->leftFor + 0.002 >= self::LEAVE_DELAY)) {
                $this->ticking = false;
                $this->update(true);

                return false;
            }

            return true;
        });
    }

    /** Folds now, whatever kept it unfolded (island.c:226-237). */
    private function fold(bool $notify): void
    {
        [$this->pinned, $this->holdLeft, $this->leftFor] = [false, 0.0, null];
        // still under the pointer: it must leave before it unfolds it again
        $this->hoverBlocked = $this->hovered;
        $this->set(false, $notify);
    }

    public function handle(SilkEvent $e): bool
    {
        switch ($e->type) {
            case EventType::Enter:
                [$this->hovered, $this->leftFor] = [true, null];
                $this->update(true);
                $this->aimHover();

                return false;
            case EventType::Leave:
                [$this->hovered, $this->leftFor] = [false, 0.0];
                $this->update(true);
                $this->aimHover();

                return false;
            case EventType::MouseDown:
                // a press on the island itself, not on a control inside it
                if ($e->button !== MouseButton::LEFT || $this->tree()?->hitWithin($this, $e->x, $e->y) !== $this) {
                    return false;
                }
                if ($this->noClick) {
                    return true;
                }
                if ($this->expanded && $this->pinned) {
                    $this->fold(true);
                } else {
                    // folded: it unfolds; unfolded by the pointer or for a while: it now stays
                    [$this->pinned, $this->hoverBlocked] = [true, false];
                    $this->update(true);
                }

                return true;
            default:
                return false;
        }
    }

    // ---------------------------------------------------------------- size (island.c:108-131, 276-298, 345-357)

    private function sizeFor(bool $expanded, Size $available): Size
    {
        $c = $this->content($expanded);
        $s = $c !== null ? $c->measure($available) : new Size(0, 0);

        return $expanded ? new Size(max($s->w + 36, 180.0), max($s->h + 30, 64.0)) : new Size(max($s->w + 28, 96.0), max($s->h + 10, 30.0));
    }

    protected function measureContent(Size $available): Size
    {
        [$this->folded, $this->unfolded] = [$this->sizeFor(false, $available), $this->sizeFor(true, $available)];
        $target = $this->expanded ? $this->unfolded : $this->folded;
        if (! $this->sized) {
            $this->width->snap($target->w);
            $this->height->snap($target->h);
            $this->sized = true;
        } else {
            // it springs open and settles shut
            $preset = $this->expanded ? SpringPreset::Bouncy : SpringPreset::Smooth;
            [$this->width->preset, $this->height->preset] = [$preset, $preset];
            $this->width->target($target->w);
            $this->height->target($target->h);
        }

        $this->measuredAt = [$this->width->value(), $this->height->value()];

        return new Size(max($this->width->value(), min($this->folded->w, $this->unfolded->w)), max($this->height->value(), min($this->folded->h, $this->unfolded->h)));
    }

    /** Each content keeps its place in its own capsule, hung from the top and centred: the capsule opens around it. */
    protected function arrangeContent(Rect $r): void
    {
        foreach ([false, true] as $expanded) {
            $c = $this->content($expanded);
            if ($c === null) {
                continue;
            }
            $size = $expanded ? $this->unfolded : $this->folded;
            [$px, $py] = $expanded ? [18.0, 15.0] : [14.0, 5.0];
            $c->arrange(new Rect($r->x + floor(($r->w - $size->w) / 2) + $px, $r->y + $py, max(0.0, $size->w - 2 * $px), max(0.0, $size->h - 2 * $py)));
        }
    }

    public function springs(): array
    {
        return [$this->hover(), $this->width, $this->height];
    }

    /** While the capsule changes size, the layout follows it. */
    public function settleMotion(): void
    {
        parent::settleMotion();
        $this->measuredAt !== [$this->width->value(), $this->height->value()] && $this->invalidateLayout();
    }

    // ---------------------------------------------------------------- paint (island.c:383-485)

    private static function smoothstep(float $from, float $to, float $x): float
    {
        $t = min(1.0, max(0.0, ($x - $from) / ($to - $from)));

        return $t * $t * (3 - 2 * $t);
    }

    /** The capsule: its springs' size, hung from the top and centred in what the parent gave it. */
    private function capsule(): Rect
    {
        $given = $this->bounds();
        $w = min($given->w, max($this->width->value(), min($this->folded->w, $this->unfolded->w)));
        $h = min($given->h, max($this->height->value(), min($this->folded->h, $this->unfolded->h)));

        return new Rect($given->x + floor(($given->w - $w) / 2), $given->y, $w, $h);
    }

    /** Only the capsule takes the pointer: given more room (a window-wide overlay), the rest lets it through. */
    public function hits(float $x, float $y): bool
    {
        return $this->capsule()->contains($x, $y);
    }

    public function paint(Painter $p): void
    {
        $look = $this->look();
        $b = $this->capsule();
        [$folded, $unfolded] = [$this->folded, $this->unfolded];
        $span = ($unfolded->w - $folded->w) + ($unfolded->h - $folded->h);
        // how far the capsule has gone from its folded size to its unfolded one (past 1 while it bounces)
        $t = $span > 1 ? (($b->w - $folded->w) + ($b->h - $folded->h)) / $span : ($this->expanded ? 1.0 : 0.0);
        $open = min(1.0, max(0.0, $t));
        $hover = $this->expanded ? 0.0 : min(1.0, max(0.0, $this->hover()->value()));
        // a capsule when folded, a rounded panel when unfolded
        $radius = min($b->h / 2, 15 + 13 * $open);
        $top = $this->attached ? 0.0 : $radius;
        $radii = [$top, $top, $radius, $radius];

        // under the pointer, a folded island swells a little: it is about to open (island.c:483-484)
        [$gx, $gy] = [1 + 0.035 * $hover, 1 + 0.06 * $hover];
        $swell = $hover > 0;
        $cx = $b->x + $b->w / 2;
        $swell && $p->pushTransform($gx, $cx * (1 - $gx), ($b->y + $b->h / 2) * (1 - $gy), $gy);

        $glass = $look->material()->isGlass();
        if (! $look->dark()) {
            // it was given a light look: a panel of that look (island.c:416-423)
            if ($glass) {
                $p->glass($b, $radii, GlassStyle::of($look, SurfaceKind::Popup, $hover)->withShadow(12 + 14 * $open));
            } else {
                $p->shadow($b, $radius, 12 + 18 * $open, $look->color(Role::Shadow), 0.0, 3 + 5 * $open - (12 + 18 * $open) / 4);
                $p->rect($b, $look->color(Role::Popup), $radii, $look->color(Role::PopupBorder), 1.0);
            }
        } elseif ($glass) {
            // smoked glass: the window shows through, darkened enough for white text whatever is behind (island.c:434-448)
            $material = $look->material();
            $tint = $this->hasColor(Role::Panel) ? $this->color(Role::Panel, $look) : new Color(7 / 255, 7 / 255, 11 / 255, 184 / 255);
            if ($material === Material::TintedGlass) {
                $accent = $look->color(Role::Accent);
                $tint = self::mixColor($tint, new Color($accent->red, $accent->green, $accent->blue, $accent->alpha * 0.7), 0.45);
            }
            $p->glass($b, $radii, GlassStyle::tune($look, new GlassLook(
                $tint,
                new Color(1, 1, 1, 0.34 + 0.12 * $hover),
                $material === Material::Glass ? 0.3 : 1.0,
                $material === Material::Glass ? 18.0 : 12.0,
                1.6,
                0.02 * $hover,
                7.0 + 3.0 * $open,
                12 + 20 * $open,
                new Color(0, 0, 0, 0.34 + 0.1 * $open),
            )));
        } else {
            $c = $look->part('Island', 'Capsule', State::Normal);
            $x = $c->extra;
            $fill = $this->hasColor(Role::Panel) ? $this->color(Role::Panel, $look) : $c->background;
            $black = $c->shadowColor ?? new Color(0, 0, 0);
            $alpha = fn (Color $k, float $a) => new Color($k->red, $k->green, $k->blue, min(1.0, max(0.0, $a)));
            // two shadows: a tight one that sets it on the window, a wide one that lifts it when it opens
            $p->shadow($b, $radius, 5.0, $alpha($black, (float) ($x['tight'] ?? 0.0)), 0.0, 1 - 5 / 4);
            $blur = 14 + 26 * $open;
            $p->shadow($b, $radius, $blur, $alpha($black, (float) ($x['lift'] ?? 0.0) + (float) ($x['liftOpen'] ?? 0.0) * $open), 0.0, 4 + 8 * $open - $blur / 4);
            $p->rect($b, $fill ?? new Color(0, 0, 0, 0), $radii, $alpha($c->border ?? new Color(1, 1, 1), (float) ($x['rim'] ?? 0.0) + (float) ($x['rimHover'] ?? 0.0) * $hover), 1.0);
        }

        // the contents, inside the largest rectangle clear of the round corners
        $inset = $radius * 0.2929;
        $clipTop = $this->attached ? 0.0 : $inset;
        $p->pushClip(new Rect($b->x + $inset, $b->y + $clipTop, $b->w - 2 * $inset, $b->h - $clipTop - $inset));
        // the small one leaves early, growing a little as if it came closer
        $this->paintContent($p, $this->content(false), 1 - self::smoothstep(0.0, 0.3, $t), 1 + 0.16 * $open, $cx, $b->y + $folded->h / 2);
        // the large one hangs from the top and always fits: it grows with the capsule and shows as it gets readable
        $fit = min($b->w / max($unfolded->w, 1.0), $b->h / max($unfolded->h, 1.0));
        $this->paintContent($p, $this->content(true), self::smoothstep(0.22, 0.8, $t), min(1.0, max(0.3, $fit)), $cx, $b->y);
        $p->popClip();
        $swell && $p->popTransform();
    }

    private static function mixColor(Color $a, Color $b, float $t): Color
    {
        return new Color($a->red + ($b->red - $a->red) * $t, $a->green + ($b->green - $a->green) * $t, $a->blue + ($b->blue - $a->blue) * $t, $a->alpha + ($b->alpha - $a->alpha) * $t);
    }

    /** A content faded and scaled about a point; the capsule's clip, pushed before, stays where it is (island.c:367-381). */
    private function paintContent(Painter $p, ?WidgetContract $c, float $opacity, float $scale, float $px, float $py): void
    {
        if ($c === null || $opacity <= 0.004) {
            return;
        }
        $p->pushOpacity($opacity);
        $scale != 1.0 && $p->pushTransform($scale, $px * (1 - $scale), $py * (1 - $scale));
        $c->paint($p);
        $scale != 1.0 && $p->popTransform();
        $p->popOpacity();
    }
}
