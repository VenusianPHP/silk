<?php

namespace Silk\Widgets;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Mail\NodeActivated;
use Silk\Contracts\Mail\NodeContextRequested;
use Silk\Contracts\Mail\NodeGraphEdited;
use Silk\Contracts\Mail\NodeLinked;
use Silk\Contracts\Mail\NodeMoved;
use Silk\Contracts\Mail\NodeRemoved;
use Silk\Contracts\Mail\NodeSelectionChanged;
use Silk\Contracts\Mail\NodeUnlinked;
use Silk\Hosts\InputAdapter;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;
use Silk\Contracts\Look\FontSpec;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\State;
use Silk\Contracts\Widget\Widget as WidgetContract;
use Silk\Core\Tree\Container;
use Silk\Looks\Canvas\CanvasLook;
use Silk\Looks\Canvas\CanvasPreset;
use Silk\Looks\Canvas\CanvasShape;
use Silk\Looks\Canvas\PartOverrides;
use Silk\Looks\Canvas\PartStyle;
use Silk\Painter\RavenPaint;
use Silk\Widgets\Concerns\MeasuresText;
use Silk\Widgets\Nodes\Link;
use Silk\Widgets\Nodes\Node;
use Silk\Widgets\Nodes\Pin;
use Silk\Widgets\Nodes\View;
use Surface\Contracts\Drawing\Effects\Blur;
use Surface\Contracts\Drawing\Effects\FilterChain;
use Surface\Contracts\Drawing\StrokeStyle;
use Surface\Contracts\Rasterize\StrokeCap;
use Surface\NutsAndBolts\Color;

/**
 * Nodes with pins on a canvas that pans and zooms, linked by wires: Raven's widgets/node_graph.c, its API
 * (raven_tools.h Rnode_graph_*) one to one. A node has a title, inputs on its left, outputs on its right, and may
 * hold a widget. An output links to inputs of its type (or of none); an input takes one link, unless it takes
 * several (setPinMultiple). Nodes, pins and links are named by integers, all different within one graph. The
 * widgets inside the nodes are its children, laid out in the layer (View) and shown at the zoom.
 */
class NodeGraph extends Container
{
    use MeasuresText;

    public const float ROW = 24.0;

    public const float PAD = 10.0;

    public const float PIN = 5.0;

    public const float MIN_WIDTH = 148.0;

    public const float GRID = 24.0;

    /** Below this zoom the widgets inside the nodes are too small to be used: clicks go to the node. */
    public const float ZOOM_USABLE = 0.45;

    /** The colours Raven picks for a type from its name (g_ng_palette). */
    public const array PALETTE = ['#64D2FF', '#30D158', '#FFD60A', '#FF9F0A', '#FF6482', '#BF5AF2', '#7D7AFF', '#66D4CF', '#FFB340', '#DA8FFF'];

    /** @var list<Node> in paint order: the last is on top */
    private array $nodes = [];

    /** @var list<Pin> */
    private array $pins = [];

    /** @var list<Link> */
    private array $links = [];

    /** @var array<string, array{color: ?Color, style: PartOverrides}> by type name */
    private array $types = [];

    private int $nextId = 1;

    private View $view;

    /** Something was drawn: from now on what changes glides (and a new node pops in). */
    private bool $painted = false;

    /** frameAll was asked before the widget had a size. */
    private bool $framePending = false;

    private bool $geometryDirty = true;

    private float $headerHeight = 30.0;

    private CanvasShape $linkShape = CanvasShape::Bezier;

    private CanvasPreset $preset = CanvasPreset::Auto;

    /** What was given to the parts of the graph. */
    private PartOverrides $over;

    private bool $gridHidden = false;

    /** null: the pulses run while the pointer is over the graph; true always; false never. */
    private ?bool $flow = null;

    private bool $readOnly = false;

    /** Delete removes the selected nodes too (Raven: with a removed callback set). */
    private bool $removable = false;

    /** @var (\Closure(int, int): bool)|null asked before the user links an output to an input */
    private ?\Closure $connectFilter = null;

    private const int DRAG_NONE = 0;

    /** Moving the selected nodes. */
    private const int DRAG_MOVE = 1;

    /** Pulling a wire out of a pin. */
    private const int DRAG_LINK = 2;

    /** A selection rectangle. */
    private const int DRAG_BOX = 3;

    private const int DRAG_PAN = 4;

    private int $drag = self::DRAG_NONE;

    private bool $dragMoved = false;

    private int $hotNode = 0;

    private int $hotPin = 0;

    private int $hotLink = 0;

    private bool $mouseIn = false;

    private bool $hasFocus = false;

    /** The pin the wire being pulled hangs from; the link picked up by its input end (hidden meanwhile); where it would go. */
    private int $wirePin = 0;

    private int $wireDetached = 0;

    private int $wireTarget = 0;

    /** The wire's free end, on the canvas. */
    private float $wireX = 0.0;

    private float $wireY = 0.0;

    private \Silk\Core\Motion\Spring $wireSnap;

    private \Silk\Core\Motion\Spring $wireSwayX;

    private \Silk\Core\Motion\Spring $wireSwayY;

    /** @var array{float, float}|null */
    private ?array $wireLast = null;

    /** The selection rectangle, from the widget's corner. */
    private Rect $box;

    /** @var list<array{pieces: list<list<float>>, from: Color, to: Color, left: \Silk\Core\Motion\Spring}> wires going away, drawing back to their first point */
    private array $ghosts = [];

    private \Silk\Core\Motion\Spring $flowShown;

    private float $flowTime = 0.0;

    /** The clock of the running nodes' breathing ring. */
    private float $activeTime = 0.0;

    private bool $ticking = false;

    /** @var array<string, list<array{\Surface\NutsAndBolts\Path, bool}>> the grid's paths while the view stays where it is (see paintGrid) */
    private array $gridPaths = [];

    private float $pressX = 0.0;

    private float $pressY = 0.0;

    /** Where the button went down: in the widget, and on the canvas. */
    private float $pressCx = 0.0;

    private float $pressCy = 0.0;

    private int $dragNode = 0;

    private bool $boxAdd = false;

    private bool $spaceDown = false;

    /** What the wheel turned that did not make a stop of the zoom yet (wheels that turn by fractions). */
    private float $wheelRest = 0.0;

    private float $panFromX = 0.0;

    private float $panFromY = 0.0;

    private float $panSpeedX = 0.0;

    private float $panSpeedY = 0.0;

    private float $panClock = 0.0;

    private ?\Closure $clock = null;

    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->focusable = true;
        $this->view = new View;
        $this->over = new PartOverrides;
        $this->box = new Rect(0, 0, 0, 0);
        $this->wireSnap = new \Silk\Core\Motion\Spring(0.0, \Silk\Core\Motion\SpringPreset::Snappy);
        $this->wireSwayX = new \Silk\Core\Motion\Spring(0.0, \Silk\Core\Motion\SpringPreset::Wobbly);
        $this->wireSwayY = new \Silk\Core\Motion\Spring(0.0, \Silk\Core\Motion\SpringPreset::Wobbly);
        $this->flowShown = new \Silk\Core\Motion\Spring(0.0, \Silk\Core\Motion\SpringPreset::Smooth);
    }

    // ---------------------------------------------------------------- model (node_graph.c:266-450)

    private function changed(): void
    {
        $this->geometryDirty = true;
        $this->invalidateLayout();
    }

    private function nodeOf(int $id): ?Node
    {
        foreach ($this->nodes as $n) {
            if ($n->id === $id) {
                return $n;
            }
        }

        return null;
    }

    private function pinOf(int $id): ?Pin
    {
        foreach ($this->pins as $p) {
            if ($p->id === $id) {
                return $p;
            }
        }

        return null;
    }

    private function linkOf(int $id): ?Link
    {
        foreach ($this->links as $l) {
            if ($l->id === $id) {
                return $l;
            }
        }

        return null;
    }

    private function node(int $id): Node
    {
        return $this->nodeOf($id) ?? throw new \InvalidArgumentException("No node {$id} in NodeGraph '{$this->name()}'.");
    }

    private function pin(int $id): Pin
    {
        return $this->pinOf($id) ?? throw new \InvalidArgumentException("No pin {$id} in NodeGraph '{$this->name()}'.");
    }

    private function type(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }
        $this->types[$name] ??= ['color' => null, 'style' => new PartOverrides];

        return $name;
    }

    /** A pin's colour: its type's, else one picked from the type's name; a pin of no type is grey (rvn_ng_pin_color). */
    public function pinColor(int $pin): Color
    {
        $p = $this->pin($pin);
        if ($p->type === null) {
            // neither a colour nor the text: the text mixed toward the row's grey
            $grey = $this->look()->part('NodeGraph', 'Pin', State::Normal)->extra['anyType'] ?? $this->look()->color(Role::TextDisabled);

            return self::opaque(RavenPaint::mix($this->look()->color(Role::Text), $grey, 0.55));
        }

        return $this->types[$p->type]['color'] ?? Color::hex(self::PALETTE[hexdec(hash('fnv1a32', $p->type)) % count(self::PALETTE)]);
    }

    private static function opaque(Color $c): Color
    {
        return new Color($c->red, $c->green, $c->blue, 1.0);
    }

    private function inputLinkOf(int $input): ?Link
    {
        foreach ($this->links as $l) {
            if ($l->to === $input) {
                return $l;
            }
        }

        return null;
    }

    private function removeLinkAt(int $index): void
    {
        array_splice($this->links, $index, 1);
        $this->invalidatePaint();
    }

    /** The link between two pins, made if needed; the input loses the one it had unless it takes several (rvn_ng_add_link). */
    private function addLink(int $output, int $input, bool $drawIn): int
    {
        $multiple = $this->pinOf($input)?->multiple ?? false;
        foreach ($this->links as $i => $l) {
            if ($l->to !== $input) {
                continue;
            }
            if ($l->from === $output) {
                return $l->id;
            }
            if ($multiple) {
                continue;
            }
            $this->removeLinkAt($i);
            break;
        }
        $this->links[] = $link = new Link($this->nextId++, $output, $input);
        $link->grow->target(1.0);
        (! $drawIn || ! $this->painted) && $link->grow->snap(1.0);
        $this->invalidatePaint();

        return $link->id;
    }

    private function removePinAt(int $index): void
    {
        $id = $this->pins[$index]->id;
        for ($i = count($this->links); $i > 0; $i--) {
            ($this->links[$i - 1]->from === $id || $this->links[$i - 1]->to === $id) && $this->removeLinkAt($i - 1);
        }
        array_splice($this->pins, $index, 1);
    }

    private function removeNodeNow(int $id): void
    {
        $index = array_search($id, array_map(fn (Node $n) => $n->id, $this->nodes), true);
        if ($index === false) {
            return;
        }
        for ($i = count($this->pins); $i > 0; $i--) {
            $this->pins[$i - 1]->node === $id && $this->removePinAt($i - 1);
        }
        $content = $this->nodes[$index]->content;
        array_splice($this->nodes, $index, 1);
        $content !== null && $this->remove($content);
        $this->changed();
    }

    /** The node goes over the others (rvn_ng_raise). */
    private function raise(int $id): void
    {
        foreach ($this->nodes as $i => $n) {
            if ($n->id === $id) {
                array_splice($this->nodes, $i, 1);
                $this->nodes[] = $n;

                return;
            }
        }
    }

    // ---------------------------------------------------------------- geometry (node_graph.c:455-504)

    private function titleFont(): FontSpec
    {
        $f = $this->textStyle();

        return new FontSpec($f->family, $f->size, 700, $f->italic);
    }

    private function smallFont(): FontSpec
    {
        $f = $this->textStyle();

        return new FontSpec($f->family, floor($f->size * 0.9), $f->weight, $f->italic);
    }

    /** The height of the headers, as the look gives it, and the links' shape (rvn_ng_header_height). */
    private function headerHeightNow(): float
    {
        $c = $this->canvasLook();
        $header = $this->part($c, 'Header', State::Normal);
        $link = $this->part($c, 'Link', State::Normal);
        $this->linkShape = $link->shape === CanvasShape::Default ? CanvasShape::Bezier : $link->shape;

        return max(16.0, min(80.0, $header->size > 0 ? $header->size : 30.0));
    }

    private function geometry(): void
    {
        if (! $this->geometryDirty || $this->tree()?->look === null) {
            return;
        }
        $this->geometryDirty = false;
        $this->headerHeight = $this->headerHeightNow();
        $row = $this->headerHeight < 26.0 ? 21.0 : self::ROW;
        [$bold, $small] = [$this->titleFont(), $this->smallFont()];
        foreach ($this->nodes as $node) {
            [$inputs, $outputs, $widestIn, $widestOut] = [0, 0, 0.0, 0.0];
            $width = max(self::MIN_WIDTH, $this->textSize($node->title, $bold)->w + 30.0);
            $content = new Size(0, 0);
            foreach ($this->pins as $pin) {
                if ($pin->node !== $node->id) {
                    continue;
                }
                $pin->labelWidth = $this->textSize($pin->label, $small)->w;
                $at = $pin->output ? $outputs++ : $inputs++;
                $pin->y = $this->headerHeight + 7.0 + $row * ($at + 0.5);
                $pin->output ? $widestOut = max($widestOut, $pin->labelWidth) : $widestIn = max($widestIn, $pin->labelWidth);
            }
            $rows = max($inputs, $outputs);
            $width = max($width, $widestIn + $widestOut + 28.0 + ($widestIn > 0 && $widestOut > 0 ? 20.0 : 0.0));
            if ($node->content !== null && $node->content->isVisible()) {
                $content = $node->content->measure(new Size(INF, INF));
                $width = max($width, $content->w + self::PAD * 2);
            }
            $node->w = ceil($width);
            foreach ($this->pins as $pin) {
                $pin->node === $node->id && $pin->x = $pin->output ? $node->w : 0.0;
            }
            $top = $this->headerHeight + ($rows > 0 ? 7.0 + $row * $rows : 0.0);
            if ($content->h > 0) {
                $node->contentRect = new Rect(self::PAD, $top + 6.0, $node->w - self::PAD * 2, $content->h);
                $node->h = ceil($top + 6.0 + $content->h + self::PAD);
            } else {
                $node->contentRect = new Rect(self::PAD, $top, $node->w - self::PAD * 2, 0.0);
                $node->h = ceil($top + ($rows > 0 ? 5.0 : 8.0));
            }
        }
    }

    /** @return array{float, float} */
    private function pinAt(Pin $pin): array
    {
        $node = $this->nodeOf($pin->node);

        return $node === null ? [0.0, 0.0] : [$node->x + $pin->x, $node->y + $pin->y];
    }

    // ---------------------------------------------------------------- look (node_graph.c:1045-1230)

    private function canvasLook(): CanvasLook
    {
        return new CanvasLook($this->look(), $this->preset);
    }

    /** A part: the base, the theme's rows for the preset, what was given to the graph. */
    private function part(CanvasLook $c, string $part, State $state): PartStyle
    {
        return $c->part('NodeGraph', $part, $state, $this->base(...), $this->over);
    }

    /** What the look gives each part, derived from the theme's roles; the presets' own colours are style rows (rvn_ng_base). */
    private function base(PartStyle $s, string $part, State $state, CanvasLook $c): void
    {
        [$selected, $hovered] = [$state === State::Selected, $state === State::Hover];
        [$black, $white] = [new Color(0, 0, 0), new Color(1, 1, 1)];
        switch ($part) {
            case 'Canvas':
                // (the presets' own colours are NodeStyles rows)
                $s->radius = $c->look->metric(Metric::CornerRadius);
                $s->borderWidth = 1.0;
                $s->background = $c->input;
                $s->border = match ($c->preset) {
                    'modern' => PartStyle::fade($c->text, 0.08),
                    'blueprint' => PartStyle::fade($black, 0.7),
                    default => $c->border,
                };
                break;
            case 'Grid':
                $s->foreground = $c->is('blender') ? $white : $c->text;
                [$s->shape, $s->size, $s->opacity] = match ($c->preset) {
                    'modern' => [CanvasShape::Dots, 24.0, $c->dark ? 0.16 : 0.22],
                    'blueprint' => [CanvasShape::Lines, 16.0, 0.05],
                    'blender' => [CanvasShape::Dots, 20.0, 0.09],
                    'neon' => [CanvasShape::Cross, 28.0, 0.16],
                    'windows' => [CanvasShape::Dots, 20.0, 0.11],
                    default => [CanvasShape::Dots, 22.0, 0.12],
                };
                break;
            case 'Node':
                $s->borderWidth = 1.0;
                $s->shadowColor = PartStyle::fade($black, $c->dark ? 0.34 : 0.14);
                $s->background = self::opaque($c->popup);
                $s->border = $c->popupBorder;
                [$s->radius, $s->shadow] = [10.0, 16.0];
                switch ($c->preset) {
                    case 'windows':
                        [$s->background, $s->radius, $s->shadow] = [$c->popup, 8.0, 14.0];
                        break;
                    case 'macos':
                        $s->border = PartStyle::fade($c->dark ? $c->text : $black, $c->dark ? 0.12 : 0.1);
                        [$s->radius, $s->shadow] = [12.0, 22.0];
                        break;
                    case 'gnome':
                        // a card of Adwaita: no outline, a short shadow
                        $s->background = $c->dark ? self::opaque(RavenPaint::mix(self::opaque($c->window), self::opaque($c->text), 0.08)) : $white;
                        [$s->borderWidth, $s->radius, $s->shadow] = [0.0, 12.0, 10.0];
                        $s->shadowColor = PartStyle::fade($black, $c->dark ? 0.4 : 0.16);
                        break;
                    case 'modern':
                        $s->background = $white;
                        $s->border = PartStyle::fade($c->text, $c->dark ? 0.07 : 0.08);
                        [$s->radius, $s->shadow] = [16.0, 30.0];
                        $s->shadowColor = PartStyle::fade($black, $c->dark ? 0.5 : 0.1);
                        break;
                    case 'blueprint':
                        $s->border = PartStyle::fade($black, 0.85);
                        [$s->radius, $s->shadow, $s->shadowColor] = [6.0, 16.0, PartStyle::fade($black, 0.6)];
                        break;
                    case 'blender':
                        [$s->radius, $s->shadow, $s->shadowColor] = [5.0, 8.0, PartStyle::fade($black, 0.45)];
                        break;
                    case 'neon':
                        // (no border colour: the node's own)
                        [$s->border, $s->borderWidth, $s->radius, $s->shadow, $s->glow] = [null, 1.5, 12.0, 0.0, 0.45];
                        break;
                }
                // (under the pointer the outline goes 0.18 toward the text: after the rows, in look())
                $hovered && $c->is('neon') && $s->glow = 0.7;
                if ($selected) {
                    // (Blueprint, Blender and Neon have colours of their own: rows)
                    [$s->border, $s->borderWidth, $s->glow] = match ($c->preset) {
                        'blueprint' => [$s->border, 2.0, 0.35],
                        'blender' => [$s->border, 1.5, 0.0],
                        'neon' => [$white, 2.0, 1.0],
                        default => [self::opaque($c->accent), $c->is('windows') ? 1.5 : 2.0, 0.45],
                    };
                }
                break;
            case 'Header':
                $s->opacity = 1.0;
                [$s->shape, $s->size] = match ($c->preset) {
                    'modern' => [CanvasShape::None, 36.0],
                    'blueprint' => [CanvasShape::Gradient, 28.0],
                    'blender' => [CanvasShape::Solid, 24.0],
                    'neon' => [CanvasShape::Stripe, 30.0],
                    'gnome' => [CanvasShape::Tint, 32.0],
                    default => [CanvasShape::Tint, 30.0],
                };
                break;
            case 'Pin':
                [$s->shape, $s->size, $s->borderWidth] = [CanvasShape::Circle, 4.5, 1.8];
                match ($c->preset) {
                    'windows' => [$s->size, $s->borderWidth] = [4.0, 1.6],
                    'modern' => [$s->size, $s->borderWidth] = [5.0, 2.0],
                    'blueprint' => [$s->size, $s->borderWidth, $s->glow] = [5.0, 1.6, 0.2],
                    'blender' => [$s->size, $s->borderWidth] = [3.8, 0.0],
                    'neon' => [$s->shape, $s->size, $s->borderWidth, $s->glow] = [CanvasShape::Ring, 4.8, 1.8, 0.7],
                    default => null,
                };
                break;
            case 'Link':
                [$s->shape, $s->opacity] = [CanvasShape::Bezier, 1.0];
                [$s->size, $s->glow] = match ($c->preset) {
                    'raven' => [2.2, $c->dark ? 0.15 : 0.0],
                    'modern' => [2.5, 0.0],
                    'blueprint' => [3.0, 0.4],
                    'blender' => [1.6, 0.0],
                    'neon' => [2.4, 1.0],
                    default => [2.0, 0.0],
                };
                $hovered && [$s->size, $s->glow] = [$s->size + 0.8, min($s->glow + 0.25, 1.2)];
                $selected && [$s->size, $s->glow] = [$s->size + 1.0, min($s->glow + 0.35, 1.2)];
                break;
            case 'Indicator':
                // (Neon's pink: a row)
                $s->background = self::opaque($c->accent);
                break;
        }
    }

    // ---------------------------------------------------------------- layout

    /** rvn_node_graph_measure: the widgets inside the nodes are measured there. */
    protected function measureContent(Size $available): Size
    {
        $this->geometryDirty = true;
        $this->geometry();

        return new Size(480.0, 320.0);
    }

    /** rvn_node_graph_arrange: the view is worked out, each node's widget laid out in the layer. */
    protected function arrangeContent(Rect $r): void
    {
        $this->geometry();
        [$this->view->w, $this->view->h] = [$r->w, $r->h];
        $this->framePending && $this->frameAllNow(false);
        $this->view->sync();
        $this->placeContents();
    }

    /** Each node's widget, laid out in the layer: canvas + pan / zoom, from the widget's corner (rvn_ng_place_contents). */
    private function placeContents(): void
    {
        [$sx, $sy] = [$this->view->panX / $this->view->drawZoom, $this->view->panY / $this->view->drawZoom];
        $b = $this->bounds();
        foreach ($this->nodes as $node) {
            if ($node->content !== null && $node->content->isVisible()) {
                $node->content->arrange(new Rect($b->x + $node->x + $node->contentRect->x + $sx, $b->y + $node->y + $node->contentRect->y + $sy,
                    $node->contentRect->w, $node->contentRect->h));
            }
        }
    }

    private function frameAllNow(bool $animate): void
    {
        if ($this->view->w <= 1.0 || $this->view->h <= 1.0) {
            $this->framePending = true;

            return;
        }
        $this->framePending = false;
        $this->geometry();
        if ($this->nodes === []) {
            $this->view->set(0.0, 0.0, 1.0, $animate && $this->painted);

            return;
        }
        [$x0, $y0, $x1, $y1] = [INF, INF, -INF, -INF];
        foreach ($this->nodes as $n) {
            [$x0, $y0, $x1, $y1] = [min($x0, $n->x), min($y0, $n->y), max($x1, $n->x + $n->w), max($y1, $n->y + $n->h)];
        }
        $zoom = min($this->view->w / ($x1 - $x0 + 96.0), $this->view->h / ($y1 - $y0 + 96.0));
        // never larger than life, and on a round value: text is sharp there
        $zoom = max(View::ZOOM_MIN, min(1.0, floor($zoom * 20.0) / 20.0));
        $this->view->set(($x0 + $x1) * 0.5, ($y0 + $y1) * 0.5, $zoom, $animate && $this->painted);
        $this->placeContents();
    }

    public function springs(): array
    {
        $springs = [...parent::springs(), ...$this->view->springs(), $this->wireSnap, $this->wireSwayX, $this->wireSwayY, $this->flowShown,
            ...array_map(fn (array $g) => $g['left'], $this->ghosts)];
        foreach ($this->nodes as $n) {
            array_push($springs, ...$n->springs());
        }
        foreach ($this->pins as $p) {
            array_push($springs, $p->hot, $p->pulse);
        }
        foreach ($this->links as $l) {
            array_push($springs, ...$l->springs());
        }

        return $springs;
    }

    // ---------------------------------------------------------------- look of a frame (node_graph.c:1181-1229)

    /**
     * Every part as the look gives it now, and the colours the paint derives (rvn_ng_resolve).
     *
     * @return array<string, mixed>
     */
    private function resolve(): array
    {
        $c = $this->canvasLook();
        $l = ['c' => $c];
        foreach (['body' => ['Canvas', State::Normal], 'grid' => ['Grid', State::Normal], 'node' => ['Node', State::Normal], 'nodeHot' => ['Node', State::Hover],
            'nodeSel' => ['Node', State::Selected], 'header' => ['Header', State::Normal], 'pin' => ['Pin', State::Normal], 'link' => ['Link', State::Normal],
            'linkHot' => ['Link', State::Hover], 'linkSel' => ['Link', State::Selected], 'badge' => ['Indicator', State::Normal]] as $key => [$part, $state]) {
            $l[$key] = $this->part($c, $part, $state);
        }
        // under the pointer a node's outline goes 0.18 toward the text, from the outline the rows gave it (rvn_ng_base)
        ! $c->is('neon') && PartStyle::shows($l['nodeHot']->border) && $l['nodeHot']->border = RavenPaint::mix($l['nodeHot']->border, self::opaque($c->text), 0.18);
        // the presets of the night have a text of their own (a row on the canvas); else the widget's text
        $l['text'] = $l['body']->foreground ?? $this->color(Role::Text, $this->look());
        $l['dim'] = PartStyle::fade($l['text'], 0.55);
        $f = $this->textStyle();
        [$l['size'], $l['small'], $l['bold']] = [$f->size, floor($f->size * 0.9), 700];
        if ($c->is('blender')) {
            // (Blender: smaller, and not bold)
            [$l['size'], $l['small'], $l['bold']] = [floor($f->size * 0.92), floor(floor($f->size * 0.92) * 0.92), $f->weight];
        }
        $l['titleFont'] = new FontSpec($f->family, $l['size'], $l['bold'], $f->italic);
        $l['smallFont'] = new FontSpec($f->family, $l['small'], $f->weight, $f->italic);
        // (glass only where the look is the theme's own)
        $l['glass'] = in_array($c->preset, ['raven', 'macos', 'gnome', 'windows'], true) && $this->look()->material()->isGlass();
        $this->linkShape = $l['link']->shape === CanvasShape::Default ? CanvasShape::Bezier : $l['link']->shape;

        return $l;
    }

    /** A colour a row gives a part as an extra prop (the presets' constants), or null. */
    private function extraColor(CanvasLook $c, string $part, string $key): ?Color
    {
        $v = $this->look()->part('NodeGraph', $part, State::Normal, $c->classes())->extra[$key] ?? null;

        return $v instanceof Color ? $v : null;
    }

    /** Pins of the type "exec" (and their links) are arrows in the Blueprint look (rvn_ng_exec_type). */
    private function execType(Pin $pin): bool
    {
        return $pin->type !== null && in_array(strtolower($pin->type), ['exec', 'execution', 'flow'], true);
    }

    /** A pin's look: the graph's, then its type's, then its own (rvn_ng_pin_look). */
    private function pinLook(array $l, Pin $pin): PartStyle
    {
        $s = clone $l['pin'];
        $l['c']->is('blueprint') && $this->execType($pin) && $s->shape = CanvasShape::Arrow;
        $pin->type !== null && $this->types[$pin->type]['style']->applyTo('Pin', $s);
        $pin->style->applyTo('Pin', $s);

        return $s;
    }

    /** The look of the links leaving an output, over $s (rvn_ng_link_look). */
    private function linkLook(?Pin $pin, PartStyle $s): PartStyle
    {
        if ($pin !== null) {
            $pin->type !== null && $this->types[$pin->type]['style']->applyTo('Link', $s);
            $pin->style->applyTo('Link', $s);
        }

        return $s;
    }

    /** The colour a pin is drawn in (and its links): its own, else its type's; Blueprint's exec pins white (rvn_ng_pin_paint_color). */
    private function pinPaintColor(array $l, Pin $pin, PartStyle $pl): Color
    {
        if (PartStyle::shows($pl->background)) {
            return $pl->background;
        }
        if ($l['c']->is('blueprint') && $this->execType($pin) && $this->types[$pin->type]['color'] === null) {
            return $this->extraColor($l['c'], 'Pin', 'exec') ?? $this->pinColor($pin->id);
        }

        return $this->pinColor($pin->id);
    }

    // ---------------------------------------------------------------- paint (node_graph.c:1281-1782)

    /** rvn_node_graph_paint: the canvas and its grid; then, through the view, the wires, the nodes and the wire being pulled. */
    public function paint(\Silk\Contracts\Painter\Painter $p): void
    {
        $this->geometry();
        $this->view->sync();
        $this->placeContents();
        $l = $this->resolve();
        $b = $this->bounds();
        $zoom = $this->view->drawZoom;

        // the pulses run while the pointer is over the graph, or always (and in Neon), or never
        $mode = $this->flow ?? ($l['c']->is('neon') ? true : null);
        $on = ($mode === true || ($mode === null && ($this->mouseIn || $this->drag !== self::DRAG_NONE))) && $this->links !== [] && $this->isEnabled();
        $l['flow'] = max(0.0, min(1.0, $this->flowShown->target($on ? 1.0 : 0.0)->value()));
        $this->keepTicking($on || array_filter($this->nodes, fn (Node $n) => $n->active) !== []);

        // ---- the canvas
        $body = $l['body'];
        $p->rect($b, $body->background ?? new Color(0, 0, 0, 0), $body->radius, $body->border, $body->borderWidth);
        $p->pushClip(new Rect($b->x + 1, $b->y + 1, $b->w - 2, $b->h - 2));
        $this->paintGrid($p, $l, $b);

        // ---- what zooms: the canvas through the view
        $p->pushTransform($zoom, $b->x + $this->view->panX, $b->y + $this->view->panY);
        foreach ($this->pins as $pin) {
            $pin->linked = false;
        }
        // what shows, on the canvas (a node or a wire out of it is not painted: Raven culls the nodes, the GPU the wires)
        [$vx0, $vy0] = $this->view->toCanvas(0, 0);
        [$vx1, $vy1] = $this->view->toCanvas($b->w, $b->h);
        foreach ($this->links as $link) {
            [$from, $to] = [$this->pinOf($link->from), $this->pinOf($link->to)];
            if ($from === null || $to === null || ($link->id === $this->wireDetached && $this->drag === self::DRAG_LINK)) {
                continue;
            }
            $from->linked = $to->linked = true;
            [$ax, $ay] = $this->pinAt($from);
            [$bx, $by] = $this->pinAt($to);
            $hover = $link->hover->target(($link->id === $this->hotLink && $this->drag === self::DRAG_NONE) || $link->selected ? 1.0 : 0.0)->value();
            $ll = clone $l['link'];
            $ll->mix($link->selected ? $l['linkSel'] : $l['linkHot'], max(0.0, min(1.0, $hover)));
            $this->linkLook($from, $ll);
            [$sx, $sy] = $this->swing($link->swayX, $link->swayY, $link->last, $ax, $ay, $bx, $by);
            $pieces = Nodes\Wire::build($ll->shape === CanvasShape::Default ? CanvasShape::Bezier : $ll->shape, $ax, $ay, $bx, $by, $sx, $sy);
            $reach = max($ll->size, 0.5) + 30.0;
            $box = array_merge(...array_map(fn (array $q) => [$this->pieceBox($q, 0.0)], $pieces));
            if (min(array_map(fn (Rect $r) => $r->x, $box)) - $reach > $vx1 || max(array_map(fn (Rect $r) => $r->x + $r->w, $box)) + $reach < $vx0
                || min(array_map(fn (Rect $r) => $r->y, $box)) - $reach > $vy1 || max(array_map(fn (Rect $r) => $r->y + $r->h, $box)) + $reach < $vy0) {
                continue;
            }
            [$fc, $tc] = [$this->pinPaintColor($l, $from, $this->pinLook($l, $from)), $this->pinPaintColor($l, $to, $this->pinLook($l, $to))];
            if ($l['c']->is('blender') && ($quiet = $this->extraColor($l['c'], 'Link', 'quiet')) !== null) {
                // (the wires of Blender are quiet: their colours lean to grey)
                [$fc, $tc] = [RavenPaint::mix($fc, $quiet, 0.35), RavenPaint::mix($tc, $quiet, 0.35)];
            }
            $grow = max(0.0, min(1.0, $link->grow->target(1.0)->value()));
            $grow < 0.995
                ? $this->paintWire($p, $l, Nodes\Wire::start($pieces, $grow), $ll, $fc, RavenPaint::mix($fc, $tc, $grow), $link->selected, 0.0)
                : $this->paintWire($p, $l, $pieces, $ll, $fc, $tc, $link->selected, $l['flow']);
        }
        // wires that were let go draw back into their pin
        foreach ($this->ghosts as $i => $ghost) {
            $left = $ghost['left']->target(0.0)->value();
            if ($left < 0.03) {
                unset($this->ghosts[$i]);

                continue;
            }
            $this->paintWire($p, $l, Nodes\Wire::start($ghost['pieces'], max(0.0, min(1.0, $left))), $l['link'],
                PartStyle::fade($ghost['from'], $left), PartStyle::fade(RavenPaint::mix($ghost['from'], $ghost['to'], $left), $left), false, 0.0);
        }
        $this->ghosts = array_values($this->ghosts);

        foreach ($this->nodes as $node) {
            if ($node->x - 40 > $vx1 || $node->x + $node->w + 40 < $vx0 || $node->y - 40 > $vy1 || $node->y + $node->h + 50 < $vy0) {
                // (out of sight: it is not animated, only kept up to date)
                $node->appear->value() === 0.0 && $node->appear->snap(1.0);

                continue;
            }
            $this->paintNode($p, $l, $node, $b);
        }
        $this->paintPulledWire($p, $l);
        $p->popTransform();

        if ($this->drag === self::DRAG_BOX && $this->dragMoved) {
            $p->rect($this->box->moved($b->x, $b->y), PartStyle::fade($l['c']->accent, 0.14), 4.0, PartStyle::fade($l['c']->accent, 0.85), 1.0);
        }
        $p->popClip();
        $this->hasFocus && $p->rect($b->grow(2), new Color(0, 0, 0, 0), $body->radius + 2, $this->color(Role::Focus, $this->look()), 2.0);
        $this->painted = true;
    }

    /**
     * The swing of a wire: its middle is left behind by what its ends just did, and comes back (rvn_ng_swing).
     *
     * @param  array{float, float}|null  $last
     * @return array{float, float}
     */
    private function swing(\Silk\Core\Motion\Spring $sx, \Silk\Core\Motion\Spring $sy, ?array &$last, float $x0, float $y0, float $x1, float $y1): array
    {
        [$mx, $my] = [($x0 + $x1) * 0.5, ($y0 + $y1) * 0.5];
        $sx->target(0.0);
        $sy->target(0.0);
        if ($last !== null && abs($mx - $last[0]) + abs($my - $last[1]) > 0.01) {
            $sx->snap(max(-70.0, min(70.0, $sx->value() - ($mx - $last[0]) * 0.8)))->target(0.0);
            $sy->snap(max(-70.0, min(70.0, $sy->value() - ($my - $last[1]) * 0.8)))->target(0.0);
        }
        $last = [$mx, $my];

        return [$sx->value(), $sy->value()];
    }

    /**
     * A wire along its pieces (rvn_ng_paint_path + Raven's curve primitive, ui.frag:270-304), composed of Surface's
     * paths: the colour shades along the length in short pieces; the light around it is the stroke, wider, blurred in
     * a layer; each pulse is a bright stretch of it, blurred, running from the start. Raven measures along the curve's
     * parameter, this along its length: on these curves the two differ little.
     *
     * @param  list<list<float>>  $pieces
     */
    private function paintWire(\Silk\Contracts\Painter\Painter $p, array $l, array $pieces, PartStyle $link, Color $from, Color $to, bool $selected, float $flow): void
    {
        if ($pieces === []) {
            return;
        }
        $opacity = $link->opacity > 0 ? $link->opacity : 1.0;
        $thickness = max($link->size, 0.5);
        PartStyle::shows($link->foreground) && $from = $to = $link->foreground;
        [$from, $to] = [PartStyle::fade($from, $from->alpha * $opacity), PartStyle::fade($to, $to->alpha * $opacity)];
        if ($from->alpha <= 0 && $to->alpha <= 0) {
            return;
        }
        // device pixels a canvas unit is: the filters work in them
        $k = $p->scale() * $this->view->drawZoom;
        if ($selected && ! $l['c']->is('neon')) {
            // a selected wire has a sleeve of the text's colour
            foreach ($pieces as $piece) {
                $p->stroke(Nodes\Wire::path($piece), PartStyle::fade(self::opaque($l['text']), 0.85), new StrokeStyle($thickness + 4.0, StrokeCap::ROUND));
            }
        }
        [$lengths, $total] = Nodes\Wire::lengths($pieces);
        $at = 0.0;
        foreach ($pieces as $i => $piece) {
            $path = Nodes\Wire::path($piece);
            $len = $path->length();
            [$t0, $t1] = $total > 0 ? [$at / $total, ($at + $lengths[$i]) / $total] : [0.0, 1.0];
            [$c0, $c1] = [RavenPaint::mix($from, $to, $t0), RavenPaint::mix($from, $to, $t1)];
            $box = $this->pieceBox($piece, $thickness + 3.0 + 8.0 * $link->glow + 12.0);
            if ($link->glow > 0.01) {
                // the light around it: radius 3 + 8 glow, as bright as 0.18 + 0.4 glow
                $halo = 3.0 + 8.0 * $link->glow;
                $p->layer($box, fn ($q) => $this->strokeShaded($q, $path, $len, $c0, $c1, $thickness + $halo), max(0.0, min(0.85, 0.18 + 0.4 * $link->glow)),
                    filter: FilterChain::of(Blur::gaussian($halo * 0.5 * $k)));
            }
            $this->strokeShaded($p, $path, $len, $c0, $c1, $thickness);
            if ($flow > 0.01) {
                // pulses a length of 120 apart, running at 0.85 a second; each a bright stretch of the wire
                $pulses = max($lengths[$i] / 120.0, 0.25);
                $phase = fmod($this->flowTime * 0.85 + $at / 120.0, 1.0);
                $half = 0.16 * $len / $pulses;
                for ($n = 0; $n <= ceil($pulses); $n++) {
                    $a = ($n + $phase) / $pulses;
                    if ($a < 0.0 || $a > 1.0) {
                        continue;
                    }
                    $glint = RavenPaint::mix(RavenPaint::mix($c0, $c1, $a), new Color(1, 1, 1, RavenPaint::mix($c0, $c1, $a)->alpha), 0.6);
                    $stretch = $path->segment(max(0.0, $a * $len - $half), min($len, $a * $len + $half));
                    $p->layer($box, fn ($q) => $q->stroke($stretch, $glint, new StrokeStyle($thickness + 1.5, StrokeCap::ROUND)), $flow,
                        filter: FilterChain::of(Blur::gaussian(max(0.5, $thickness * 0.6) * $k)));
                }
            }
            $at += $lengths[$i];
        }
    }

    /** A stroke shading from $c0 to $c1 along its length, in pieces of about 8 units (one when the two are alike). */
    private function strokeShaded(\Silk\Contracts\Painter\Painter $p, \Surface\NutsAndBolts\Path $path, float $len, Color $c0, Color $c1, float $width): void
    {
        // (opaque pieces overlap at round caps unseen; see-through ones meet edge to edge)
        $style = new StrokeStyle($width, $c0->alpha >= 1.0 && $c1->alpha >= 1.0 ? StrokeCap::ROUND : StrokeCap::BUTT);
        $n = $c0->toHex() === $c1->toHex() ? 1 : max(1, (int) ceil($len / 8.0));
        for ($i = 0; $i < $n; $i++) {
            $p->stroke($n === 1 ? $path : $path->segment($len * $i / $n, $len * ($i + 1) / $n), RavenPaint::mix($c0, $c1, ($i + 0.5) / $n), $style);
        }
    }

    /** A piece's box (it stays inside its four points), grown by $margin. @param list<float> $piece */
    private function pieceBox(array $piece, float $margin): Rect
    {
        $xs = [$piece[0], $piece[2], $piece[4], $piece[6]];
        $ys = [$piece[1], $piece[3], $piece[5], $piece[7]];

        return new Rect(min($xs) - $margin, min($ys) - $margin, max($xs) - min($xs) + 2 * $margin, max($ys) - min($ys) + 2 * $margin);
    }

    /** The wire being pulled, over the nodes: its end is what is looked at (node_graph.c:1714-1755). */
    private function paintPulledWire(\Silk\Contracts\Painter\Painter $p, array $l): void
    {
        $anchor = $this->drag === self::DRAG_LINK ? $this->pinOf($this->wirePin) : null;
        if ($anchor === null) {
            return;
        }
        $target = $this->pinOf($this->wireTarget);
        [$fx, $fy] = $this->pinAt($anchor);
        $snap = max(0.0, min(1.0, $this->wireSnap->target($target !== null ? 1.0 : 0.0)->value()));
        [$ex, $ey] = [$this->wireX, $this->wireY];
        $color = $other = $this->pinPaintColor($l, $anchor, $this->pinLook($l, $anchor));
        if ($target !== null) {
            $other = $this->pinPaintColor($l, $target, $this->pinLook($l, $target));
            [$tx, $ty] = $this->pinAt($target);
            [$ex, $ey] = [$ex + ($tx - $ex) * $snap, $ey + ($ty - $ey) * $snap];
        }
        $ll = clone $l['link'];
        $ll->mix($l['linkHot'], 0.6 + $snap * 0.4);
        $this->linkLook($anchor->output ? $anchor : $target, $ll);
        [$sx, $sy] = $this->swing($this->wireSwayX, $this->wireSwayY, $this->wireLast, $fx, $fy, $ex, $ey);
        $shape = $ll->shape === CanvasShape::Default ? CanvasShape::Bezier : $ll->shape;
        $anchor->output
            ? $this->paintWire($p, $l, Nodes\Wire::build($shape, $fx, $fy, $ex, $ey, $sx, $sy), $ll, $color, $other, false, 1.0)
            : $this->paintWire($p, $l, Nodes\Wire::build($shape, $ex, $ey, $fx, $fy, $sx, $sy), $ll, $other, $color, false, 1.0);
        if ($target === null) {
            // the loose end
            RavenPaint::mark($p, \Silk\Painter\MarkShape::Circle, $ex, $ey, 4.5, $color);
            RavenPaint::mark($p, \Silk\Painter\MarkShape::Circle, $ex, $ey, 2.0, new Color(1, 1, 1, 0.85));
        }
    }

    /** The grid of the canvas: dots, lines or crosses, every fourth one stronger (rvn_ng_paint_grid). */
    private function paintGrid(\Silk\Contracts\Painter\Painter $p, array $l, Rect $b): void
    {
        $grid = $l['grid'];
        $shape = $grid->shape === CanvasShape::Default ? CanvasShape::Dots : $grid->shape;
        if ($this->gridHidden || $shape === CanvasShape::None) {
            return;
        }
        $step = max(4.0, min(400.0, $grid->size > 0 ? $grid->size : 22.0)) * $this->view->drawZoom;
        $opacity = $grid->opacity > 0 ? $grid->opacity : 0.12;
        $fg = self::opaque($grid->foreground ?? $l['c']->text);
        [$minor, $major] = [PartStyle::fade($fg, $opacity), PartStyle::fade($fg, min($opacity * 2.0, 1.0))];
        // when they get too close, every other one goes
        while ($step < ($shape === CanvasShape::Lines ? 10.0 : 16.0)) {
            $step *= 2.0;
        }
        [$panX, $panY] = [$this->view->panX, $this->view->panY];
        [$firstX, $firstY] = [$panX - floor($panX / $step) * $step - $step, $panY - floor($panY / $step) * $step - $step];
        $index = fn (float $first, float $pan) => (int) floor(($first - $pan) / $step + 0.5);
        [$col0, $row0] = [$index($firstX, $panX), $index($firstY, $panY)];
        if ($shape === CanvasShape::Lines) {
            $strong = fn (int $i) => $i % 4 === 0;
            for ([$x, $col] = [$firstX, $col0]; $x < $b->w + $step; $x += $step, $col++) {
                $p->rect(new Rect($b->x + floor($x), $b->y, 1.0, $b->h), $strong($col) ? $major : $minor);
            }
            for ([$y, $row] = [$firstY, $row0]; $y < $b->h + $step; $y += $step, $row++) {
                $p->rect(new Rect($b->x, $b->y + floor($y), $b->w, 1.0), $strong($row) ? $major : $minor);
            }

            return;
        }
        // dots and crosses: a few paths of all of them, built once for as long as the view stays where it is, drawn in one
        // fill each (a thousand rect() calls a frame cost more than the engine's drawing of them)
        [$ox, $oy] = [floor($firstX), floor($firstY)];
        $key = implode('|', [$shape->name, $step, $b->w, $b->h, $firstX - $ox, $firstY - $oy, $col0 & 3, $row0 & 3, $p->scale()]);
        if (! isset($this->gridPaths[$key])) {
            count($this->gridPaths) >= 4 && array_shift($this->gridPaths);
            $this->gridPaths[$key] = $this->buildGrid($shape, $step, $b->w + $step - $ox, $b->h + $step - $oy, $firstX - $ox, $firstY - $oy, $col0, $row0, 0.25 / $p->scale());
        }
        $p->pushTransform(1.0, $b->x + $ox, $b->y + $oy);
        foreach ($this->gridPaths[$key] as [$path, $strong]) {
            $p->fill($path, $strong ? $major : $minor);
        }
        $p->popTransform();
    }

    /**
     * The grid's dots or crosses as paths (at most four), from the corner of the first one: [path, whether strong].
     * $limitX and $limitY end the rows and columns, from the corner. A dot is a circle flattened here once, within $tolerance, to the straight sides the engine would make of it, so
     * the engine only copies them; each is centred on a whole unit as Raven's are (floor).
     *
     * @return list<array{\Surface\NutsAndBolts\Path, bool}>
     */
    private function buildGrid(CanvasShape $shape, float $step, float $limitX, float $limitY, float $fx, float $fy, int $col0, int $row0, float $tolerance): array
    {
        $paths = $shape === CanvasShape::Cross ? [[new \Surface\NutsAndBolts\Path, false], [new \Surface\NutsAndBolts\Path, false], [new \Surface\NutsAndBolts\Path, true], [new \Surface\NutsAndBolts\Path, true]]
            : [[new \Surface\NutsAndBolts\Path, false], [new \Surface\NutsAndBolts\Path, true]];
        $circle = [];
        foreach ([1.0, 1.5] as $half) {
            $circle[(string) $half] = self::circle($half, $tolerance);
        }
        for ([$y, $row] = [$fy, $row0]; $y < $limitY; $y += $step, $row++) {
            for ([$x, $col] = [$fx, $col0]; $x < $limitX; $x += $step, $col++) {
                $big = $col % 4 === 0 && $row % 4 === 0;
                [$px, $py] = [floor($x), floor($y)];
                if ($shape === CanvasShape::Cross) {
                    $arm = $big ? 4.0 : 2.5;
                    [$h, $v] = $big ? [$paths[2][0], $paths[3][0]] : [$paths[0][0], $paths[1][0]];
                    self::box($h, $px - $arm, $py, $arm * 2 + 1, 1.0);
                    self::box($v, $px, $py - $arm, 1.0, $arm * 2 + 1);

                    continue;
                }
                $path = $big ? $paths[1][0] : $paths[0][0];
                foreach ($circle[(string) ($big ? 1.5 : 1.0)] as $i => [$cx, $cy]) {
                    $i === 0 ? $path->moveTo($px + $cx, $py + $cy) : $path->lineTo($px + $cx, $py + $cy);
                }
                $path->close();
            }
        }

        return $paths;
    }

    /** A rectangle as a closed path. */
    private static function box(\Surface\NutsAndBolts\Path $path, float $x, float $y, float $w, float $h): void
    {
        $path->moveTo($x, $y)->lineTo($x + $w, $y)->lineTo($x + $w, $y + $h)->lineTo($x, $y + $h)->close();
    }

    /** The corners of a circle of radius $half about the origin, flattened as the engine flattens it within $tolerance. @return list<array{float, float}> */
    private static function circle(float $half, float $tolerance): array
    {
        static $made = [];

        return $made["{$half}|{$tolerance}"] ??= (function () use ($half, $tolerance) {
            $k = 0.5522847498307936 * $half;
            $circle = (new \Surface\NutsAndBolts\Path)->moveTo($half, 0)->cubicTo($half, $k, $k, $half, 0, $half)->cubicTo(-$k, $half, -$half, $k, -$half, 0)
                ->cubicTo(-$half, -$k, -$k, -$half, 0, -$half)->cubicTo($k, -$half, $half, -$k, $half, 0)->close();

            return $circle->flatten($tolerance)[0]['points'];
        })();
    }

    /** One node, in canvas units (rvn_ng_paint_node): shadow, glow, the running ring, body, header, edge, badge, pins, content. */
    private function paintNode(\Silk\Contracts\Painter\Painter $p, array $l, Node $node, Rect $b): void
    {
        $rect = new Rect($node->x, $node->y, $node->w, $node->h);
        $held = $node->selected && $this->drag === self::DRAG_MOVE && $this->dragMoved;
        $hot = $node->id === $this->hotNode && $this->isEnabled() && $this->drag === self::DRAG_NONE;
        $appear = $node->appear->target(1.0)->value();
        $lift = max(0.0, min(1.3, $node->lift->target($held ? 1.0 : ($hot ? 0.3 : 0.0))->value()));
        $glow = max(0.0, min(1.0, $node->glow->target($node->selected ? 1.0 : 0.0)->value()));
        $c = $l['c'];
        $tone = $node->color ?? $l['c']->accent;
        $shown = max(0.0, min(1.0, $appear * 1.4));
        if ($shown <= 0.004) {
            return;
        }
        // the look of the node: under the pointer, selected; then what was given to it
        $nl = clone $l['node'];
        $nl->mix($l['nodeHot'], max(0.0, min(1.0, $lift / 0.3)) * ($held ? 0.0 : 1.0));
        $nl->mix($l['nodeSel'], $glow);
        $header = clone $l['header'];
        $node->style->applyTo('Node', $nl);
        $node->style->applyTo('Header', $header);
        $c->is('blender') && ($grey = $this->extraColor($c, 'Node', 'tone')) !== null && $tone = RavenPaint::mix(self::opaque($tone), $grey, 0.38);
        $radius = max($nl->radius, 0.0);
        $body = self::opaque($nl->background ?? $c->popup);
        $border = PartStyle::shows($nl->border) ? $nl->border : self::opaque($tone);

        // it pops in, and swells a little while it is carried
        $scale = (0.86 + 0.14 * $appear) * (1.0 + 0.012 * $lift);
        [$cx, $cy] = [$rect->x + $rect->w * 0.5, $rect->y + $rect->h * 0.5];
        $p->pushTransform($scale, $cx * (1 - $scale), $cy * (1 - $scale));
        $p->pushOpacity($shown * ($nl->opacity > 0 && $nl->opacity < 1 ? $nl->opacity : 1.0));

        // it stands above the canvas, higher while it is carried
        if ($nl->shadow > 0 && PartStyle::shows($nl->shadowColor)) {
            $p->shadow(new Rect($rect->x, $rect->y + 2.0 + $nl->shadow * 0.12 + $lift * 7.0, $rect->w, $rect->h), $radius, $nl->shadow + $lift * 16.0,
                PartStyle::fade($nl->shadowColor, max(0.0, min(1.0, $nl->shadowColor->alpha + $lift * 0.1))));
        }
        // light around it: selected, or the look of light
        if ($nl->glow > 0.01 && $nl->borderWidth > 0) {
            $p->shadow($rect->grow(1), $radius + 1.0, 6.0 + 10.0 * $nl->glow, PartStyle::fade($border, max(0.0, min(0.85, 0.55 * $nl->glow))));
        }
        // a node that runs: a soft ring of its colour breathes around it
        $running = max(0.0, min(1.0, $node->activeShown->target($node->active ? 1.0 : 0.0)->value()));
        if ($running > 0.01) {
            $ring = self::opaque($node->activeColor ?? $c->success);
            $breath = 0.5 + 0.5 * sin($this->activeTime * 4.2);
            $grow = 2.0 + 3.0 * $breath;
            $p->shadow($rect->grow($grow), $radius + $grow, 10.0 + 8.0 * $breath, PartStyle::fade($ring, (0.35 + 0.35 * $breath) * $running));
            $p->rect($rect->grow(2), new Color(0, 0, 0, 0), $radius + 2.0, PartStyle::fade($ring, (0.55 + 0.45 * $breath) * $running), 1.5);
        }
        if ($l['glass']) {
            $g = \Silk\Looks\GlassStyle::of($this->look(), \Silk\Contracts\Look\SurfaceKind::Popup);
            $p->glass($rect, $radius, new \Silk\Contracts\Painter\GlassLook($g->tint, $g->rim, $g->frost, $g->refraction, $g->saturation, $g->glow, $g->bevel, 0.0, $g->shadowColor));
        } else {
            $p->rect($rect, $nl->background ?? $body, $radius);
        }
        $this->paintHeader($p, $l, $nl, $header, $node, $tone, $body);
        // its edge: the look's, the node's colour (Neon), the selection
        $nl->borderWidth > 0 && $p->rect($rect, new Color(0, 0, 0, 0), $radius, $border, $nl->borderWidth);
        // the badge: a pill astride the top right corner, it pops when its text appears
        $badge = max(0.0, min(1.2, $node->badgeShown->value()));
        if ($badge > 0.02 && $node->badge !== null) {
            $fill = PartStyle::shows($l['badge']->background) ? $l['badge']->background : self::opaque($c->accent);
            $ink = $l['badge']->foreground ?? (CanvasLook::lightness($fill) > 0.62 ? ($this->extraColor($c, 'Indicator', 'darkInk') ?? new Color(0, 0, 0)) : new Color(1, 1, 1));
            $font = new FontSpec($l['smallFont']->family, $l['small'], 700, false);
            $bh = 18.0 * $badge;
            $bw = max($bh, $this->textSize($node->badge, $font)->w + 12.0) * min(1.0, $badge);
            $pill = new Rect($rect->x + $rect->w - $bw * 0.5 - 6.0, $rect->y - $bh * 0.5, $bw, $bh);
            $p->shadow(new Rect($pill->x, $pill->y + 1.5, $pill->w, $pill->h), $bh * 0.5, 6.0, new Color(0, 0, 0, 0.3));
            $p->rect($pill, $fill, $bh * 0.5, $body, 1.0);
            $badge > 0.6 && $this->textIn($p, $node->badge, $pill, 0.5, $ink, $font);
        }
        foreach ($this->pins as $pin) {
            $pin->node === $node->id && $this->paintPin($p, $l, $node, $pin, $body);
        }
        if ($node->content !== null && $node->content->isVisible()) {
            // the widget is laid out in the layer: canvas + pan / zoom from the widget's corner
            $p->pushTransform(1.0, -$b->x - $this->view->panX / $this->view->drawZoom, -$b->y - $this->view->panY / $this->view->drawZoom);
            $node->content->render($p);
            $p->popTransform();
        }
        $p->popOpacity();
        $p->popTransform();
    }

    /** A node's header: its form, its colour, its title and an icon before it (rvn_ng_paint_header). */
    private function paintHeader(\Silk\Contracts\Painter\Painter $p, array $l, PartStyle $nl, PartStyle $header, Node $node, Color $tone, Color $body): void
    {
        $c = $l['c'];
        $rect = new Rect($node->x, $node->y, $node->w, $node->h);
        $height = $this->headerHeight;
        $radius = $nl->radius;
        $inset = $nl->borderWidth > 0 && PartStyle::shows($nl->border) ? min($nl->borderWidth, 1.0) : 0.0;
        $alone = $node->h <= $height + 9.0;
        $shape = $header->shape === CanvasShape::Default ? CanvasShape::Tint : $header->shape;
        $fill = self::opaque(PartStyle::shows($header->background) ? $header->background : $tone);
        $band = new Rect($rect->x + $inset, $rect->y + $inset, $rect->w - $inset * 2, ($alone ? $rect->h : $height) - $inset * ($alone ? 2.0 : 1.0));
        $top = max($radius - $inset, 0.0);
        $radii = [$top, $top, $alone ? $top : 0.0, $alone ? $top : 0.0];
        $titleX = $rect->x + 12.0;
        $opacity = $header->opacity > 0 ? $header->opacity : 1.0;
        switch ($shape) {
            case CanvasShape::Solid:
                $p->rect($band, PartStyle::fade(RavenPaint::mix($body, $fill, 0.9), $opacity), $radii);
                break;
            case CanvasShape::Gradient:
                // (laid over the body: opaque)
                $end = PartStyle::shows($header->gradient) ? RavenPaint::mix($body, self::opaque($header->gradient), $header->gradient->alpha * $opacity) : RavenPaint::mix($body, $fill, 0.12 * $opacity);
                RavenPaint::gradient($p, $band, $radii, RavenPaint::mix($body, $fill, 0.95 * $opacity), $end, true);
                break;
            case CanvasShape::Tint:
                $p->rect($band, PartStyle::fade(RavenPaint::mix($body, $fill, $c->dark ? 0.2 : 0.13), $opacity), $radii);
                break;
            case CanvasShape::Outline:
                $p->rect($band, new Color(0, 0, 0, 0), $radii, PartStyle::fade($fill, 0.85 * $opacity), 1.2);
                break;
        }
        if ($shape === CanvasShape::Tint || $shape === CanvasShape::Stripe) {
            // a line of the colour along the top, following the corners
            $thick = $shape === CanvasShape::Stripe ? 3.0 : 2.5;
            $p->pushClip(new Rect($rect->x, $rect->y, $rect->w, $thick + $inset));
            $p->rect(new Rect($rect->x + $inset, $rect->y + $inset, $rect->w - $inset * 2, $rect->h - $inset * 2), PartStyle::fade($fill, $opacity), max($radius - $inset, 0.0));
            $p->popClip();
        }
        // between the header and the pins
        if (! $alone && $shape !== CanvasShape::None && $shape !== CanvasShape::Stripe) {
            $p->rect(new Rect($rect->x + $inset, $rect->y + $height, $rect->w - $inset * 2, 1.0), new Color(0, 0, 0, $c->dark ? 0.3 : 0.07));
        } elseif (! $alone) {
            $p->rect(new Rect($rect->x + 12.0, $rect->y + $height, $rect->w - 24.0, 1.0), PartStyle::fade($l['text'], 0.07));
        }
        // the title: on the colour, the ink that reads on it; elsewhere the text, or the colour itself in Neon
        $title = match (true) {
            PartStyle::shows($header->foreground) => $header->foreground,
            $shape === CanvasShape::Solid || $shape === CanvasShape::Gradient => CanvasLook::lightness($fill) > 0.62 ? ($this->extraColor($c, 'Header', 'darkInk') ?? new Color(0, 0, 0)) : new Color(1, 1, 1),
            $c->is('neon') => RavenPaint::mix($fill, new Color(1, 1, 1), 0.3),
            default => $l['text'],
        };
        if ($node->icon !== null) {
            $size = min(16.0, $height - 10.0);
            $p->image($node->icon, new Rect($titleX, $rect->y + ($height - $size) * 0.5, $size, $size), 3.0);
            $titleX += $size + 7.0;
        } elseif ($shape === CanvasShape::None) {
            // no band of colour: a dot of it
            $p->rect(new Rect($titleX, $rect->y + ($height - 8.0) * 0.5, 8.0, 8.0), $fill, 4.0);
            $titleX += 16.0;
        }
        $this->textIn($p, $node->title, new Rect($titleX, $rect->y + ($shape === CanvasShape::Stripe ? 2.0 : 0.0), $rect->x + $rect->w - 12.0 - $titleX, $height),
            0.0, PartStyle::fade($title, $title->alpha * $opacity), $l['titleFont']);
    }

    /** A pin (rvn_ng_paint_pin): its mark cut out of the node's edge, hollow until linked, the ring a new link sends out, its label. */
    private function paintPin(\Silk\Contracts\Painter\Painter $p, array $l, Node $node, Pin $pin, Color $body): void
    {
        $anchor = $this->drag === self::DRAG_LINK ? $this->pinOf($this->wirePin) : null;
        [$cx, $cy] = [$node->x + $pin->x, $node->y + $pin->y];
        $pl = $this->pinLook($l, $pin);
        $color = $this->pinPaintColor($l, $pin, $pl);
        $shape = $pl->shape === CanvasShape::Default ? CanvasShape::Circle : $pl->shape;
        [$target, $opacity] = [0.0, 1.0];
        if ($anchor !== null) {
            // while a wire is pulled: the pins it could go to show themselves, the others step back
            match (true) {
                $pin->id === $this->wireTarget => $target = 1.5,
                $pin === $anchor => $target = 0.6,
                $anchor->canLink($pin) => $target = 0.5,
                default => $opacity = 0.3,
            };
        } elseif ($pin->id === $this->hotPin && $this->isEnabled() && ! $this->readOnly) {
            $target = 1.0;
        }
        $opacity *= $pl->opacity > 0 ? $pl->opacity : 1.0;
        $grow = $pin->hot->target($target)->value();
        $pulse = max(0.0, min(1.0, $pin->pulse->target(0.0)->value()));
        $half = max(($pl->size > 0 ? $pl->size : 4.5) + $grow * 1.6, 1.5);
        $glow = max($pl->glow, max(0.0, min(1.0, $grow * 0.8)));
        if ($shape !== CanvasShape::None) {
            $mark = self::markShape($shape);
            RavenPaint::markGlow($p, $mark, $cx, $cy, $half, PartStyle::fade($color, $opacity), $glow);
            // a collar of the node's body, so that the pin is cut out of its edge
            RavenPaint::mark($p, $mark, $cx, $cy, $half + 2.0, PartStyle::fade($body, $opacity < 1.0 ? 0.85 : 1.0));
            PartStyle::shows($pl->border) && RavenPaint::mark($p, $mark, $cx, $cy, $half + 1.0, PartStyle::fade($pl->border, $pl->border->alpha * $opacity));
            // nothing plugged in: hollow (a ring), unless the look keeps its pins full
            $hollow = $shape === CanvasShape::Ring || (! $pin->linked && $pin->id !== $this->wireTarget && $pl->borderWidth > 0);
            $inside = RavenPaint::mix($body, new Color(0, 0, 0), $l['c']->dark ? 0.3 : 0.04);
            RavenPaint::mark($p, $mark, $cx, $cy, $half, PartStyle::fade($color, $opacity), $hollow, max($pl->borderWidth, 1.4), PartStyle::fade($inside, $opacity));
            $shape === CanvasShape::Ring && $pin->linked && RavenPaint::mark($p, \Silk\Painter\MarkShape::Circle, $cx, $cy, $half * 0.42, PartStyle::fade($color, $opacity));
        }
        if ($pulse > 0.01) {
            $ring = $half + 2.0 + (1.0 - $pulse) * 13.0;
            $p->rect(new Rect($cx - $ring, $cy - $ring, $ring * 2, $ring * 2), new Color(0, 0, 0, 0), $ring, PartStyle::fade($color, $pulse), 1.6);
        }
        if ($pin->label !== '') {
            $text = PartStyle::fade($l['text'], ($target > 0.4 ? 1.0 : 0.78) * min($opacity * 1.6, 1.0));
            $row = $this->headerHeight < 26.0 ? 21.0 : self::ROW;
            $gap = max(13.0, $half + 8.0);
            $pin->output
                ? $this->textIn($p, $pin->label, new Rect($node->x, $cy - $row * 0.5, $node->w - $gap, $row), 1.0, $text, $l['smallFont'])
                : $this->textIn($p, $pin->label, new Rect($node->x + $gap, $cy - $row * 0.5, $node->w - $gap, $row), 0.0, $text, $l['smallFont']);
        }
    }

    private static function markShape(CanvasShape $shape): \Silk\Painter\MarkShape
    {
        return match ($shape) {
            CanvasShape::Ring => \Silk\Painter\MarkShape::Ring, CanvasShape::Square => \Silk\Painter\MarkShape::Square, CanvasShape::Diamond => \Silk\Painter\MarkShape::Diamond,
            CanvasShape::Triangle => \Silk\Painter\MarkShape::Triangle, CanvasShape::Arrow => \Silk\Painter\MarkShape::Arrow, CanvasShape::None => \Silk\Painter\MarkShape::None,
            default => \Silk\Painter\MarkShape::Circle,
        };
    }

    /** $text in $r, at $align across (0 start, 0.5 middle, 1 end), on the font's baseline. */
    private function textIn(\Silk\Contracts\Painter\Painter $p, string $text, Rect $r, float $align, Color $color, FontSpec $font): void
    {
        $w = $this->textSize($text, $font)->w;
        $p->text($text, $r->x + max(0.0, $r->w - $w) * $align, $this->baselineIn($r, $font), $color, $font);
    }

    /** The pulses and the running rings need frames while they show: a ticker steps their clocks (rvn_paint_keep_animating). */
    private function keepTicking(bool $wanted): void
    {
        if (! $wanted || $this->ticking || $this->tree() === null) {
            return;
        }
        $this->ticking = true;
        $this->tree()->tick(function (float $dt): bool {
            $this->flowTime = fmod($this->flowTime + $dt, 4096.0);
            $this->activeTime = fmod($this->activeTime + $dt, 4096.0);
            $this->invalidatePaint();
            $flowing = $this->flowShown->value() > 0.01 || $this->flowShown->velocity() !== 0.0;
            $this->ticking = $flowing || array_filter($this->nodes, fn (Node $n) => $n->active || $n->activeShown->value() > 0.01) !== [];

            return $this->ticking;
        });
    }

    // ---------------------------------------------------------------- nodes (Rnode_graph_*)

    /** $x, $y: the node's top-left corner on the canvas. @return int the node */
    public function addNode(string $title, float $x, float $y): int
    {
        $this->nodes[] = $node = new Node($this->nextId++, $title, $x, $y);
        $node->appear->target(1.0);
        // what is there when the graph first shows does not pop in
        ! $this->painted && $node->appear->snap(1.0);
        $this->changed();

        return $node->id;
    }

    /** Removes a node, its pins, their links, and the widget it holds. */
    public function removeNode(int $node): static
    {
        $this->removeNodeNow($node);

        return $this;
    }

    public function clear(): static
    {
        while ($this->nodes !== []) {
            $this->removeNodeNow($this->nodes[count($this->nodes) - 1]->id);
        }
        $this->changed();

        return $this;
    }

    public function nodeCount(): int
    {
        return count($this->nodes);
    }

    /** The index-th node in the order they were made, whatever is on top now; 0 past the end. */
    public function nodeAt(int $index): int
    {
        $ids = array_map(fn (Node $n) => $n->id, $this->nodes);
        sort($ids);

        return $ids[$index] ?? 0;
    }

    public function setNodeTitle(int $node, string $title): static
    {
        $this->node($node)->title = $title;
        $this->changed();

        return $this;
    }

    public function nodeTitle(int $node): string
    {
        return $this->node($node)->title;
    }

    public function setNodePosition(int $node, float $x, float $y): static
    {
        $n = $this->node($node);
        [$n->x, $n->y] = [$x, $y];
        $this->placeContents();
        $this->invalidatePaint();

        return $this;
    }

    /** @return array{float, float} */
    public function nodePosition(int $node): array
    {
        $n = $this->node($node);

        return [$n->x, $n->y];
    }

    /** Its size on the canvas, from its title, its pins and its content. @return array{float, float} */
    public function nodeSize(int $node): array
    {
        $this->geometry();
        $n = $this->node($node);

        return [$n->w, $n->h];
    }

    /** The header's colour; null: the theme's accent. */
    public function setNodeColor(int $node, ?Color $color): static
    {
        $this->node($node)->color = $color;

        return $this->invalidatePaint();
    }

    /** Puts a widget inside the node, under its pins; null removes the one it had. */
    public function setNodeContent(int $node, ?WidgetContract $content): static
    {
        $n = $this->node($node);
        if ($content === $n->content) {
            return $this;
        }
        if ($content === $this) {
            throw new \InvalidArgumentException('A NodeGraph cannot go inside one of its own nodes.');
        }
        $n->content !== null && $this->remove($n->content);
        $n->content = $content;
        $content !== null && $this->add($content);
        $this->changed();

        return $this;
    }

    public function nodeContent(int $node): ?WidgetContract
    {
        return $this->node($node)->content;
    }

    /** A child taken away from outside lets go of its node (rvn_node_graph_children_changed). */
    public function remove(WidgetContract $child): static
    {
        foreach ($this->nodes as $n) {
            $n->content === $child && $n->content = null;
        }
        $this->geometryDirty = true;

        return parent::remove($child);
    }

    /** What findNode looks for; not shown. */
    public function setNodeName(int $node, ?string $name): static
    {
        $this->node($node)->name = $name === '' ? null : $name;

        return $this;
    }

    public function nodeName(int $node): string
    {
        return $this->node($node)->name ?? '';
    }

    /** The node of that name (without case), else of that title when it has no name; 0: none (rvn_ng_find_node). */
    public function findNode(string $name): int
    {
        if ($name === '') {
            return 0;
        }
        foreach ($this->nodes as $n) {
            if ($n->name !== null && strcasecmp($n->name, $name) === 0) {
                return $n->id;
            }
        }
        foreach ($this->nodes as $n) {
            if ($n->name === null && strcasecmp($n->title, $name) === 0) {
                return $n->id;
            }
        }

        return 0;
    }

    public function setNodeUserData(int $node, mixed $data): static
    {
        $this->node($node)->user = $data;

        return $this;
    }

    public function nodeUserData(int $node): mixed
    {
        return $this->node($node)->user;
    }

    /** Running (live debug): a soft ring breathes around it. */
    public function setNodeActive(int $node, bool $active = true): static
    {
        $this->node($node)->active = $active;

        return $this->invalidatePaint();
    }

    /** The ring's colour; null: the theme's success colour. */
    public function setNodeActiveColor(int $node, ?Color $color): static
    {
        $this->node($node)->activeColor = $color;

        return $this->invalidatePaint();
    }

    /** false: the Delete key does not remove it (the root of a tree, the output of a graph). */
    public function setNodeDeletable(int $node, bool $deletable = true): static
    {
        $this->node($node)->undeletable = ! $deletable;

        return $this;
    }

    /** A short text in a pill on the node's top right corner ("1", "!"); null or '' removes it. */
    public function setNodeBadge(int $node, ?string $text): static
    {
        $n = $this->node($node);
        $text = $text === '' ? null : $text;
        if ($text !== $n->badge) {
            $text !== null && $n->badge = $text;
            $n->badgeShown->target($text !== null ? 1.0 : 0.0);
            $text !== null && ! $this->painted && $n->badgeShown->snap(1.0);
            $this->invalidatePaint();
        }

        return $this;
    }

    public function nodeBadge(int $node): ?string
    {
        $n = $this->node($node);

        return $n->badgeShown->value() > 0.0 || $n->badgeShown->velocity() !== 0.0 ? $n->badge : null;
    }

    // ---------------------------------------------------------------- pointing (node_graph.c:232-315)

    private function nodeUnder(float $cx, float $cy): int
    {
        foreach (array_reverse($this->nodes) as $n) {
            if ($cx >= $n->x && $cy >= $n->y && $cx < $n->x + $n->w && $cy < $n->y + $n->h) {
                return $n->id;
            }
        }

        return 0;
    }

    /** Easy to catch, also when the graph is seen a little smaller; from far, the nodes come first. */
    private function pinNear(float $cx, float $cy): int
    {
        $reach = min(max(self::PIN + 6.0, 9.0 / $this->view->drawZoom), 16.0);
        [$best, $found] = [$reach * $reach, 0];
        foreach ($this->pins as $pin) {
            [$x, $y] = $this->pinAt($pin);
            $d = ($x - $cx) ** 2 + ($y - $cy) ** 2;
            if ($d < $best) {
                [$best, $found] = [$d, $pin->id];
            }
        }

        return $found;
    }

    /** The form of the links of an output pin (its type or itself may have one of their own). */
    private function pinLinkShape(Pin $pin): CanvasShape
    {
        $s = new PartStyle;
        $s->shape = $this->linkShape;
        $shape = $this->linkLook($pin, $s)->shape;

        return $shape === CanvasShape::Default ? CanvasShape::Bezier : $shape;
    }

    /** The wire of a link as it is on the canvas, with the swing its springs hold now. @return list<list<float>>|null */
    private function linkPieces(Link $link): ?array
    {
        [$from, $to] = [$this->pinOf($link->from), $this->pinOf($link->to)];
        if ($from === null || $to === null) {
            return null;
        }
        [$ax, $ay] = $this->pinAt($from);
        [$bx, $by] = $this->pinAt($to);

        return Nodes\Wire::build($this->pinLinkShape($from), $ax, $ay, $bx, $by, $link->swayX->value(), $link->swayY->value());
    }

    private function linkUnder(float $cx, float $cy): int
    {
        $reach = max(5.0, 6.0 / $this->view->drawZoom);
        foreach (array_reverse($this->links) as $link) {
            if ($link->id === $this->wireDetached && $this->drag === self::DRAG_LINK) {
                continue;
            }
            $pieces = $this->linkPieces($link);
            if ($pieces !== null && Nodes\Wire::distance($pieces, $cx, $cy) <= $reach) {
                return $link->id;
            }
        }

        return 0;
    }

    /** Where the wire being pulled would go: a pin near its end, or a pin of the node it is over (rvn_ng_wire_target). */
    private function wireTargetAt(float $cx, float $cy): int
    {
        $anchor = $this->pinOf($this->wirePin);
        if ($anchor === null) {
            return 0;
        }
        $reach = max(22.0, 20.0 / $this->view->drawZoom);
        [$best, $found] = [$reach * $reach, 0];
        foreach ($this->pins as $pin) {
            if (! $anchor->canLink($pin)) {
                continue;
            }
            [$x, $y] = $this->pinAt($pin);
            $d = ($x - $cx) ** 2 + ($y - $cy) ** 2;
            if ($d < $best) {
                [$best, $found] = [$d, $pin->id];
            }
        }
        if ($found !== 0) {
            return $found;
        }
        $over = $this->nodeUnder($cx, $cy);
        if ($over === 0 || $over === $anchor->node) {
            return 0;
        }
        // dropped on the node itself: its first pin that fits, a free one if there is one
        foreach ($this->pins as $pin) {
            if ($pin->node !== $over || ! $anchor->canLink($pin)) {
                continue;
            }
            $found === 0 && $found = $pin->id;
            if ($pin->output || $this->inputLinkOf($pin->id) === null) {
                return $pin->id;
            }
        }

        return $found;
    }

    private function updateHot(float $x, float $y): void
    {
        $b = $this->bounds();
        [$cx, $cy] = $this->view->toCanvas($x, $y);
        $inside = $this->mouseIn && $x >= 0 && $y >= 0 && $x < $b->w && $y < $b->h;
        $pin = $inside ? $this->pinNear($cx, $cy) : 0;
        $node = $inside && $pin === 0 ? $this->nodeUnder($cx, $cy) : 0;
        $link = $inside && $pin === 0 && $node === 0 ? $this->linkUnder($cx, $cy) : 0;
        if ([$pin, $node, $link] !== [$this->hotPin, $this->hotNode, $this->hotLink]) {
            [$this->hotPin, $this->hotNode, $this->hotLink] = [$pin, $node, $link];
            $this->invalidatePaint();
        }
    }

    /** The widgets inside the nodes show at the zoom (Raven's child_scale): screen = zoom * layout + (1 - zoom) * corner. */
    public function childTransform(): array
    {
        $z = $this->view->drawZoom;
        $b = $this->bounds();

        return [$z, $b->x * (1 - $z), $b->y * (1 - $z)];
    }

    /** A widget inside a node takes the pointer only where its node is the one on top, and when it is large enough to be used (rvn_node_graph_child_at). */
    public function childTakesPointer(WidgetContract $child, float $x, float $y): bool
    {
        if ($this->drag !== self::DRAG_NONE || $this->view->drawZoom < self::ZOOM_USABLE) {
            return false;
        }
        $b = $this->bounds();
        [$cx, $cy] = $this->view->toCanvas($x - $b->x, $y - $b->y);
        if ($this->pinNear($cx, $cy) !== 0) {
            return false;
        }

        return ($this->nodeOf($this->nodeUnder($cx, $cy))?->content ?? null) === $child;
    }

    // ---------------------------------------------------------------- what the user does (node_graph.c:1790-2100)

    /** @param class-string $class */
    private function notify(string $class, mixed ...$args): void
    {
        $this->post(new $class($this->window(), $this->path(), $this->uuid(), ...$args));
    }

    /** A gesture ended with a change to the graph (Raven's fire_action). */
    private function edited(): void
    {
        $this->notify(NodeGraphEdited::class);
    }

    private function now(): float
    {
        return $this->clock !== null ? ($this->clock)() : microtime(true);
    }

    /** Replaces the clock the pan's momentum reads (seconds), for tests. */
    public function setClock(?\Closure $clock): static
    {
        $this->clock = $clock;

        return $this;
    }

    /** A wire that goes away: it is seen drawing back to its first point (rvn_ng_add_ghost). @param list<list<float>> $pieces */
    private function addGhost(array $pieces, Color $from, Color $to): void
    {
        if (! $this->painted) {
            return;
        }
        $this->ghosts[] = ['pieces' => $pieces, 'from' => $from, 'to' => $to, 'left' => new \Silk\Core\Motion\Spring(1.0, \Silk\Core\Motion\SpringPreset::Snappy)];
        $this->invalidatePaint();
    }

    /** The user takes a link away: the application is told first, and a ghost of the wire draws back (rvn_ng_user_unlink). */
    private function userUnlink(int $id): void
    {
        $link = $this->linkOf($id);
        if ($link === null) {
            return;
        }
        $this->notify(NodeUnlinked::class, $link->id, $link->from, $link->to);
        $pieces = $this->linkPieces($link);
        $pieces !== null && $this->addGhost($pieces, $this->pinColor($link->from), $this->pinColor($link->to));
        $this->disconnect($id);
    }

    /** The user links two pins: the link, or 0 when the filter refused (rvn_ng_user_link). */
    private function userLink(int $pinA, int $pinB): int
    {
        $a = $this->pinOf($pinA);
        $b = $this->pinOf($pinB);
        if ($a === null || ! $a->canLink($b)) {
            return 0;
        }
        [$output, $input] = $a->output ? [$a->id, $b->id] : [$b->id, $a->id];
        if ($this->connectFilter !== null && ! ($this->connectFilter)($output, $input)) {
            return 0;
        }
        $old = $this->inputLinkOf($input);
        if ($old !== null && $this->pin($input)->multiple) {
            // an input of several links: only the same link already there is reused
            foreach ($this->links as $l) {
                if ($l->to === $input && $l->from === $output) {
                    return $l->id;
                }
            }
            $old = null;
        }
        if ($old !== null && $old->from === $output) {
            return $old->id;
        }
        $old !== null && $this->userUnlink($old->id);
        $id = $this->addLink($output, $input, false);
        if ($this->painted) {
            // a ring leaves both pins
            $this->pin($output)->pulse->snap(1.0);
            $this->pin($input)->pulse->snap(1.0);
        }
        $this->notify(NodeLinked::class, $id, $output, $input);
        $this->edited();
        $this->invalidatePaint();

        return $id;
    }

    /** Delete: the selected links go; the selected nodes too when the application lets them (rvn_ng_remove_selected). */
    private function removeSelected(): void
    {
        $any = false;
        foreach ($this->links as $l) {
            if ($l->selected) {
                $l->selected = false;
                $this->userUnlink($l->id);
                $any = true;
            }
        }
        if ($this->removable) {
            foreach ($this->nodes as $n) {
                if (! $n->selected || $n->undeletable) {
                    continue;
                }
                $n->selected = false;
                $this->notify(NodeRemoved::class, $n->id, $n->title);
                // its links go with it: the application hears about each
                foreach ($this->links as $l) {
                    ($f = $this->pinOf($l->from)) !== null && ($t = $this->pinOf($l->to)) !== null && ($f->node === $n->id || $t->node === $n->id) && $this->userUnlink($l->id);
                }
                $this->removeNodeNow($n->id);
                $any = true;
            }
        }
        if ($any) {
            $this->invalidatePaint();
            $this->notify(NodeSelectionChanged::class, 0);
            $this->edited();
        }
    }

    private function beginWire(int $pinId, float $cx, float $cy): void
    {
        $pin = $this->pin($pinId);
        // an input of several links starts a new wire: which one to pick up would be a guess
        $link = ! $pin->output && ! $pin->multiple ? $this->inputLinkOf($pinId) : null;
        // an input that is linked gives the end of its wire: it can be plugged somewhere else, or let go
        $this->wireDetached = $link->id ?? 0;
        $this->wirePin = $link->from ?? $pinId;
        $this->wireTarget = $link !== null ? $pinId : 0;
        [$this->wireX, $this->wireY, $this->wireLast] = [$cx, $cy, null];
        $this->wireSnap->snap($link !== null ? 1.0 : 0.0);
        $this->wireSwayX->snap(0.0);
        $this->wireSwayY->snap(0.0);
        $this->drag = self::DRAG_LINK;
        $this->invalidatePaint();
    }

    /** The wire being pulled, let go in the air: it draws back to the pin (rvn_ng_wire_ghost). */
    private function wireGhost(): void
    {
        $anchor = $this->pinOf($this->wirePin);
        if ($anchor === null) {
            return;
        }
        [$fx, $fy] = $this->pinAt($anchor);
        $shape = $this->pinLinkShape($anchor);
        $color = $this->pinColor($anchor->id);
        $pieces = $anchor->output
            ? Nodes\Wire::build($shape, $fx, $fy, $this->wireX, $this->wireY, $this->wireSwayX->value(), $this->wireSwayY->value())
            // (a ghost draws back to its first point: the pin)
            : self::reversed(Nodes\Wire::build($shape, $this->wireX, $this->wireY, $fx, $fy, $this->wireSwayX->value(), $this->wireSwayY->value()));
        $this->addGhost($pieces, $color, $color);
    }

    /** The same wire, from its end to its start (rvn_ng_path_reverse). @param list<list<float>> $pieces @return list<list<float>> */
    private static function reversed(array $pieces): array
    {
        return array_map(fn (array $s) => [$s[6], $s[7], $s[4], $s[5], $s[2], $s[3], $s[0], $s[1]], array_reverse($pieces));
    }

    /** A gesture ends: let go, or (with $cancel) put back (rvn_ng_end_drag). */
    private function endDrag(bool $cancel): void
    {
        [$drag, $moved] = [$this->drag, $this->dragMoved];
        [$this->drag, $this->dragMoved] = [self::DRAG_NONE, false];
        $this->invalidatePaint();
        if ($drag === self::DRAG_MOVE) {
            if ($cancel) {
                foreach ($this->nodes as $n) {
                    $n->selected && [$n->x, $n->y] = [$n->fromX, $n->fromY];
                }
                $this->placeContents();

                return;
            }
            if (! $moved) {
                return;
            }
            foreach ($this->nodes as $n) {
                if ($n->selected && ($n->x !== $n->fromX || $n->y !== $n->fromY)) {
                    [$n->fromX, $n->fromY] = [$n->x, $n->y];
                    $this->notify(NodeMoved::class, $n->id);
                }
            }
            $this->edited();
        } elseif ($drag === self::DRAG_LINK) {
            [$detached, $target, $anchor] = [$this->wireDetached, $cancel ? 0 : $this->wireTarget, $this->wirePin];
            $old = $this->linkOf($detached);
            [$this->wireDetached, $this->wireTarget] = [0, 0];
            if ($old !== null && ($cancel || $target === $old->to)) {
                return;
            }
            if ($old !== null) {
                // the wire left its input
                $ghosts = count($this->ghosts);
                $this->userUnlink($detached);
                // (the wire that draws back is the one that was pulled, not the link where it was)
                $this->ghosts = array_slice($this->ghosts, 0, $ghosts);
            }
            if ($target === 0 || $this->userLink($anchor, $target) === 0) {
                $this->wireGhost();
                $old !== null && $this->edited();
            }
        } elseif ($drag === self::DRAG_BOX) {
            $changed = false;
            if ($cancel) {
                foreach ($this->nodes as $n) {
                    $n->selected = $n->fromSelected;
                }

                return;
            }
            if (! $moved && ! $this->boxAdd) {
                // a click on the empty canvas forgets the selection
                foreach ($this->nodes as $n) {
                    $n->selected = false;
                }
                foreach ($this->links as $l) {
                    $changed = $changed || $l->selected;
                    $l->selected = false;
                }
            }
            foreach ($this->nodes as $n) {
                $changed = $changed || $n->selected !== $n->fromSelected;
            }
            $changed && $this->notify(NodeSelectionChanged::class, 0);
        } elseif ($drag === self::DRAG_PAN) {
            // let go while moving: the canvas slides a little further
            if (! $cancel && $moved && $this->now() - $this->panClock < 0.06) {
                $z = $this->view->zoom;
                $this->view->set($this->view->centerX - max(-400.0, min(400.0, $this->panSpeedX * 0.14)) / $z,
                    $this->view->centerY - max(-400.0, min(400.0, $this->panSpeedY * 0.14)) / $z, $z, $this->painted);
                $this->placeContents();
            }
        }
    }

    /** The pointer moves while a gesture runs (rvn_ng_drag_to); $x, $y from the widget's corner. */
    private function dragTo(float $x, float $y): void
    {
        [$cx, $cy] = $this->view->toCanvas($x, $y);
        if (! $this->dragMoved && abs($x - $this->pressX) + abs($y - $this->pressY) > 3.0) {
            $this->dragMoved = true;
        }
        switch ($this->drag) {
            case self::DRAG_MOVE:
                if (! $this->dragMoved) {
                    return;
                }
                foreach ($this->nodes as $n) {
                    $n->selected && [$n->x, $n->y] = [$n->fromX + ($cx - $this->pressCx), $n->fromY + ($cy - $this->pressCy)];
                }
                $this->placeContents();
                break;
            case self::DRAG_LINK:
                [$this->wireX, $this->wireY] = [$cx, $cy];
                $this->wireTarget = $this->wireTargetAt($cx, $cy);
                break;
            case self::DRAG_BOX:
                [$x0, $y0, $x1, $y1] = [min($this->pressX, $x), min($this->pressY, $y), max($this->pressX, $x), max($this->pressY, $y)];
                [$a, $b] = [$this->view->toCanvas($x0, $y0), $this->view->toCanvas($x1, $y1)];
                $this->box = new Rect($x0, $y0, $x1 - $x0, $y1 - $y0);
                if (! $this->dragMoved) {
                    return;
                }
                foreach ($this->nodes as $n) {
                    $inside = $n->x < $b[0] && $n->x + $n->w > $a[0] && $n->y < $b[1] && $n->y + $n->h > $a[1];
                    $n->selected = $inside || ($this->boxAdd && $n->fromSelected);
                }
                break;
            case self::DRAG_PAN:
                $now = $this->now();
                [$beforeX, $beforeY, $dt, $z] = [$this->view->centerX, $this->view->centerY, $now - $this->panClock, $this->view->zoom];
                $this->view->centerX = $this->panFromX - ($x - $this->pressX) / $z;
                $this->view->centerY = $this->panFromY - ($y - $this->pressY) / $z;
                // the canvas is held: it follows the hand, without easing
                $this->view->shownX->snap($this->view->centerX);
                $this->view->shownY->snap($this->view->centerY);
                if ($dt > 0.001) {
                    $this->panSpeedX = -($this->view->centerX - $beforeX) * $z / $dt;
                    $this->panSpeedY = -($this->view->centerY - $beforeY) * $z / $dt;
                }
                $this->panClock = $now;
                $this->view->sync();
                $this->placeContents();
                break;
        }
        $this->invalidatePaint();
    }

    /** Pans the view by a distance in the widget's units, at once (rvn_node_graph_pan: a trackpad's two fingers). */
    public function panBy(float $dx, float $dy): bool
    {
        if ($this->drag !== self::DRAG_NONE || ($dx === 0.0 && $dy === 0.0)) {
            return false;
        }
        $this->view->unpin();
        $this->view->set($this->view->centerX - $dx / $this->view->zoom, $this->view->centerY - $dy / $this->view->zoom, $this->view->zoom, false);
        $this->placeContents();
        $this->invalidatePaint();

        return true;
    }

    /** Zooms by a factor about a point of the widget, at once (rvn_node_graph_zoom: a pinch). */
    public function zoomBy(float $factor, float $x, float $y): bool
    {
        $before = $this->view->zoom;
        if ($this->drag !== self::DRAG_NONE) {
            return false;
        }
        $this->view->zoomAt($x, $y, $before * $factor, false);
        $this->placeContents();
        $this->invalidatePaint();

        return $this->view->zoom !== $before;
    }

    /** The whole numbers of notches a wheel has turned; what is left over is kept for the next turn (rvn_wheel_notches). */
    private function notches(float $amount): int
    {
        if ($amount === 0.0) {
            return 0;
        }
        $this->wheelRest * $amount < 0 && $this->wheelRest = 0.0;
        $wanted = $this->wheelRest + $amount;
        $whole = $wanted < 0 ? ceil($wanted) : floor($wanted);
        $this->wheelRest = $wanted - $whole;

        return (int) $whole;
    }

    public function handle(SilkEvent $e): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }
        $this->geometry();
        $b = $this->bounds();
        [$x, $y] = [$e->x - $b->x, $e->y - $b->y];
        [$cx, $cy] = $this->view->toCanvas($x, $y);

        switch ($e->type) {
            case EventType::FocusGained:
            case EventType::FocusLost:
                $this->hasFocus = $e->type === EventType::FocusGained;
                $this->invalidatePaint();

                return false;
            case EventType::Enter:
                $this->mouseIn = true;
                $this->invalidatePaint();

                return false;
            case EventType::Leave:
                $this->mouseIn = false;
                [$this->hotNode, $this->hotPin, $this->hotLink] = [0, 0, 0];
                $this->invalidatePaint();

                return false;
            case EventType::MouseMove:
                $this->mouseIn = true;
                if ($this->drag !== self::DRAG_NONE) {
                    $this->dragTo($x, $y);

                    return true;
                }
                $this->updateHot($x, $y);

                return false;
            case EventType::Wheel:
                return $this->wheel($e, $x, $y);
            case EventType::MouseDown:
                return $this->mouseDown($e, $x, $y, $cx, $cy);
            case EventType::MouseUp:
                if ($this->drag === self::DRAG_NONE) {
                    return false;
                }
                if ($this->drag === self::DRAG_PAN ? ! in_array($e->button, [MouseButton::MIDDLE, MouseButton::LEFT], true) : $e->button !== MouseButton::LEFT) {
                    return true;
                }
                // a click on one of several selected nodes, without moving: it becomes the only one
                if ($this->drag === self::DRAG_MOVE && ! $this->dragMoved && ! $e->modifiers->ctrl && ! $e->modifiers->shift && $this->selectOnly($this->dragNode, 0)) {
                    $this->notify(NodeSelectionChanged::class, $this->dragNode);
                }
                $this->endDrag(false);
                $this->updateHot($x, $y);

                return true;
            case EventType::KeyUp:
                $e->key === Key::SPACE && $this->spaceDown = false;

                return false;
            case EventType::KeyDown:
                return $this->key($e);
            default:
                return false;
        }
    }

    private function wheel(SilkEvent $e, float $x, float $y): bool
    {
        $amount = $e->wheelY;
        if ($this->drag !== self::DRAG_NONE && $this->drag !== self::DRAG_LINK) {
            return true;
        }
        if ($e->precise) {
            // a trackpad: two fingers pan the canvas; with Ctrl they zoom smoothly
            if ($e->modifiers->ctrl) {
                $amount !== 0.0 && $this->zoomBy(1.25 ** $amount, $x, $y);

                return true;
            }

            return $this->panBy($e->wheelX * InputAdapter::WHEEL_LINE, $amount * InputAdapter::WHEEL_LINE);
        }
        if ($e->wheelX !== 0.0 || ($amount !== 0.0 && $e->modifiers->shift)) {
            $slide = $e->wheelX !== 0.0 ? $e->wheelX : $amount;
            $this->view->unpin();
            $this->view->set($this->view->centerX - $slide * 60.0 / $this->view->zoom, $this->view->centerY, $this->view->zoom, $this->painted);
            $this->placeContents();
            $this->invalidatePaint();

            return true;
        }
        if ($amount !== 0.0) {
            // one stop of the zoom per notch; the fractions of a wheel that turns smoothly add up
            $zoom = $this->view->zoom;
            $steps = $this->notches($amount);
            $direction = $steps > 0 ? 1 : -1;
            for ($i = abs($steps); $i > 0; $i--) {
                $zoom = View::nextStop($zoom, $direction);
            }
            if ($zoom !== $this->view->zoom) {
                $this->view->zoomAt($x, $y, $zoom, $this->painted);
                $this->placeContents();
                $this->invalidatePaint();
            }

            return true;
        }

        return false;
    }

    private function mouseDown(SilkEvent $e, float $x, float $y, float $cx, float $cy): bool
    {
        $add = $e->modifiers->ctrl || $e->modifiers->shift;
        if ($this->drag !== self::DRAG_NONE) {
            return true;
        }
        [$this->pressX, $this->pressY, $this->pressCx, $this->pressCy, $this->dragMoved] = [$x, $y, $cx, $cy, false];
        if ($e->button === MouseButton::MIDDLE || ($e->button === MouseButton::LEFT && ($e->modifiers->alt || $this->spaceDown))) {
            $this->view->unpin();
            $this->drag = self::DRAG_PAN;
            [$this->panFromX, $this->panFromY, $this->panSpeedX, $this->panSpeedY, $this->panClock] = [$this->view->centerX, $this->view->centerY, 0.0, 0.0, $this->now()];

            return true;
        }
        if ($e->button === MouseButton::RIGHT) {
            // the menu of a node (selected first), or of the empty canvas (node 0)
            $node = $this->nodeUnder($cx, $cy);
            if ($node !== 0 && ! $this->node($node)->selected && $this->selectOnly($node, 0)) {
                $this->notify(NodeSelectionChanged::class, $node);
            }
            $this->notify(NodeContextRequested::class, $node, $x, $y);

            return true;
        }
        if ($e->button !== MouseButton::LEFT) {
            return false;
        }
        $pin = $this->pinNear($cx, $cy);
        if ($pin !== 0 && ! $this->readOnly) {
            $this->beginWire($pin, $cx, $cy);

            return true;
        }
        $nodeId = $this->nodeUnder($cx, $cy);
        if ($nodeId !== 0 && $e->clicks >= 2) {
            // double click: the application opens it (a sub-graph, an asset...)
            $this->notify(NodeActivated::class, $nodeId);

            return true;
        }
        if ($nodeId !== 0) {
            $node = $this->node($nodeId);
            $changed = false;
            if ($add) {
                $node->selected = ! $node->selected;
                $changed = true;
            } elseif (! $node->selected) {
                $changed = $this->selectOnly($nodeId, 0);
            }
            $this->raise($nodeId);
            $this->invalidatePaint();
            $changed && $this->notify(NodeSelectionChanged::class, $nodeId);
            if ($node->selected && ! $this->readOnly) {
                foreach ($this->nodes as $n) {
                    [$n->fromX, $n->fromY] = [$n->x, $n->y];
                }
                $this->drag = self::DRAG_MOVE;
                $this->dragNode = $nodeId;
            }

            return true;
        }
        $linkId = $this->linkUnder($cx, $cy);
        if ($linkId !== 0) {
            $link = $this->linkOf($linkId);
            $changed = true;
            if ($add) {
                $link->selected = ! $link->selected;
            } else {
                $changed = $this->selectOnly(0, $linkId);
            }
            $this->invalidatePaint();
            $changed && $this->notify(NodeSelectionChanged::class, 0);

            return true;
        }
        if ($e->clicks >= 2) {
            $this->frameAll();

            return true;
        }
        // the empty canvas: a selection rectangle
        foreach ($this->nodes as $n) {
            $n->fromSelected = $n->selected;
        }
        $this->box = new Rect($x, $y, 0, 0);
        $this->boxAdd = $add;
        $this->drag = self::DRAG_BOX;

        return true;
    }

    private function key(SilkEvent $e): bool
    {
        if ($e->key === Key::ESCAPE) {
            if ($this->drag === self::DRAG_NONE) {
                return false;
            }
            $this->endDrag(true);

            return true;
        }
        // the rest is for the graph itself: a key typed in a widget of a node is not for it
        if (! $this->hasFocus) {
            return false;
        }
        $step = $e->modifiers->shift ? 40.0 : 8.0;
        [$dx, $dy] = [0.0, 0.0];
        switch ($e->key) {
            case Key::SPACE:
                $this->spaceDown = true;

                return true;
            case Key::DELETE:
            case Key::BACKSPACE:
                if ($this->readOnly) {
                    return false;
                }
                $this->removeSelected();

                return true;
            case Key::HOME:
            case Key::F:
                $this->frameAll();

                return true;
            case Key::A:
                if (! $e->modifiers->ctrl) {
                    return false;
                }
                foreach ($this->nodes as $n) {
                    $n->selected = true;
                }
                $this->invalidatePaint();
                $this->notify(NodeSelectionChanged::class, 0);

                return true;
            case Key::LEFT:
                $dx = -$step;
                break;
            case Key::RIGHT:
                $dx = $step;
                break;
            case Key::UP:
                $dy = -$step;
                break;
            case Key::DOWN:
                $dy = $step;
                break;
            default:
                return false;
        }
        if ($this->readOnly || $this->drag !== self::DRAG_NONE) {
            return false;
        }
        // the arrows nudge what is selected
        $moved = array_values(array_filter($this->nodes, fn (Node $n) => $n->selected));
        if ($moved === []) {
            return false;
        }
        foreach ($moved as $n) {
            $n->x += $dx;
            $n->y += $dy;
        }
        $this->placeContents();
        $this->invalidatePaint();
        foreach ($moved as $n) {
            $this->notify(NodeMoved::class, $n->id);
        }
        $this->edited();

        return true;
    }

    // ---------------------------------------------------------------- styles (node_graph.c:2413-2524)

    /**
     * A property of the graph's parts (Raven's attributes): "Preset"/"Look", a short name (LinkShape, LinkWidth,
     * LinkGlow, LinkColor, LinkOpacity, PinShape, PinSize, PinGlow, GridStyle, GridSize, GridColor, GridOpacity,
     * NodeRadius, NodeColor, NodeBorder, NodeShadow, NodeGlow, HeaderStyle, HeaderHeight, CanvasColor, CanvasBorder)
     * or "Part.Property" ("Header.Shape", "Canvas.Background"). False for a name or value that is not one.
     */
    public function setPartStyle(string $name, string $value): bool
    {
        if (strcasecmp($name, 'Preset') === 0 || strcasecmp($name, 'Look') === 0) {
            $preset = CanvasPreset::byName($value);
            $preset !== null && $this->setPreset($preset);

            return $preset !== null;
        }
        static $short = [
            'linkshape' => ['Link', 'Shape'], 'linkwidth' => ['Link', 'Size'], 'linkglow' => ['Link', 'Glow'], 'linkcolor' => ['Link', 'Foreground'],
            'linkopacity' => ['Link', 'Opacity'], 'pinshape' => ['Pin', 'Shape'], 'pinsize' => ['Pin', 'Size'], 'pinglow' => ['Pin', 'Glow'],
            'gridstyle' => ['Grid', 'Shape'], 'gridshape' => ['Grid', 'Shape'], 'gridsize' => ['Grid', 'Size'], 'gridcolor' => ['Grid', 'Foreground'],
            'gridopacity' => ['Grid', 'Opacity'], 'noderadius' => ['Node', 'CornerRadius'], 'nodecolor' => ['Node', 'Background'],
            'nodebackground' => ['Node', 'Background'], 'nodeborder' => ['Node', 'BorderColor'], 'nodeshadow' => ['Node', 'ShadowSize'],
            'nodeglow' => ['Node', 'Glow'], 'headerstyle' => ['Header', 'Shape'], 'headerheight' => ['Header', 'Size'],
            'canvascolor' => ['Canvas', 'Background'], 'canvasborder' => ['Canvas', 'BorderColor'],
        ];
        $set = isset($short[strtolower($name)]) ? fn () => $this->over->set(...[...$short[strtolower($name)], $value]) : fn () => $this->over->setDotted($name, $value);
        $ok = $this->styled($set);
        // (the header's height and the links' shape are geometry)
        $ok && $this->changed();

        return $ok;
    }

    /**
     * A property of one node: HeaderStyle, HeaderColor, HeaderGradient, TitleColor, Background, BorderColor,
     * BorderWidth, CornerRadius, Glow, Opacity, ShadowColor, ShadowSize, or "Node.X" / "Header.X"; "" or "Default"
     * gives back the graph's (rvn_ng_node_style). The icon is setNodeIcon.
     */
    public function setNodeStyle(int $node, string $property, string $value): bool
    {
        $style = $this->node($node)->style;
        static $names = [
            'headerstyle' => ['Header', 'Shape'], 'headershape' => ['Header', 'Shape'], 'headercolor' => ['Header', 'Background'],
            'headergradient' => ['Header', 'Gradient'], 'titlecolor' => ['Header', 'Foreground'], 'textcolor' => ['Header', 'Foreground'],
            'background' => ['Node', 'Background'], 'bordercolor' => ['Node', 'BorderColor'], 'borderwidth' => ['Node', 'BorderWidth'],
            'cornerradius' => ['Node', 'CornerRadius'], 'radius' => ['Node', 'CornerRadius'], 'glow' => ['Node', 'Glow'], 'opacity' => ['Node', 'Opacity'],
            'shadowcolor' => ['Node', 'ShadowColor'], 'shadowsize' => ['Node', 'ShadowSize'],
        ];

        return $this->styled(fn () => $this->slotStyle($style, $property, $value, ['Node', 'Header'], $names));
    }

    /** An image before a node's title (Raven's Icon style), or null for none. */
    public function setNodeIcon(int $node, ?\Surface\Contracts\Framebuffers\Framebuffer $icon): static
    {
        $this->node($node)->icon = $icon;

        return $this->invalidatePaint();
    }

    /** A property of a type's pins and their links: Shape (Circle, Ring, Square, Diamond, Triangle, Arrow), Size, Glow, Color, BorderColor, BorderWidth, Opacity, LinkShape (Bezier, Straight, Step, Angled), LinkWidth, LinkGlow, LinkColor, LinkOpacity, or "Pin.X" / "Link.X". */
    public function setTypeStyle(string $type, string $property, string $value): bool
    {
        $type === '' && throw new \InvalidArgumentException('A type needs a name.');

        return $this->pinStyle($this->types[$this->type($type)]['style'], $property, $value);
    }

    /** The same for one pin. */
    public function setPinStyle(int $pin, string $property, string $value): bool
    {
        return $this->pinStyle($this->pin($pin)->style, $property, $value);
    }

    private function pinStyle(PartOverrides $style, string $property, string $value): bool
    {
        static $names = [
            'shape' => ['Pin', 'Shape'], 'size' => ['Pin', 'Size'], 'glow' => ['Pin', 'Glow'], 'color' => ['Pin', 'Background'],
            'bordercolor' => ['Pin', 'BorderColor'], 'borderwidth' => ['Pin', 'BorderWidth'], 'opacity' => ['Pin', 'Opacity'],
            'linkshape' => ['Link', 'Shape'], 'linkwidth' => ['Link', 'Size'], 'linkglow' => ['Link', 'Glow'], 'linkcolor' => ['Link', 'Foreground'],
            'linkopacity' => ['Link', 'Opacity'],
        ];
        $ok = $this->styled(fn () => $this->slotStyle($style, $property, $value, ['Pin', 'Link'], $names));
        $ok && $this->changed();

        return $ok;
    }

    /** "Slot.Property" for one of $slots, else a name of $names (rvn_item_look_set). @param array<string, array{string, string}> $names */
    private function slotStyle(PartOverrides $style, string $property, string $value, array $slots, array $names): bool
    {
        foreach ($slots as $slot) {
            if (stripos($property, $slot.'.') === 0) {
                return $style->set($slot, substr($property, strlen($slot) + 1), $value);
            }
        }
        if (! isset($names[strtolower($property)])) {
            return false;
        }

        return $style->set(...[...$names[strtolower($property)], $value]);
    }

    private function styled(\Closure $set): bool
    {
        try {
            $ok = $set();
        } catch (\InvalidArgumentException) {
            return false;
        }
        $ok && $this->invalidatePaint();

        return $ok;
    }

    // ---------------------------------------------------------------- pins

    /** $type: free text, or null for a pin that links to any type. @return int the pin */
    public function addInput(int $node, string $label, ?string $type = null): int
    {
        return $this->addPin($node, $label, $type, false);
    }

    public function addOutput(int $node, string $label, ?string $type = null): int
    {
        return $this->addPin($node, $label, $type, true);
    }

    private function addPin(int $node, string $label, ?string $type, bool $output): int
    {
        $this->node($node);
        $this->pins[] = $pin = new Pin($this->nextId++, $node, $output, $label, $this->type($type));
        $this->changed();

        return $pin->id;
    }

    public function removePin(int $pin): static
    {
        foreach ($this->pins as $i => $p) {
            if ($p->id === $pin) {
                $this->removePinAt($i);
                $this->changed();
                break;
            }
        }

        return $this;
    }

    public function pinNode(int $pin): int
    {
        return $this->pin($pin)->node;
    }

    public function isPinOutput(int $pin): bool
    {
        return $this->pin($pin)->output;
    }

    public function pinLabel(int $pin): string
    {
        return $this->pin($pin)->label;
    }

    public function pinType(int $pin): string
    {
        return $this->pin($pin)->type ?? '';
    }

    /** What findPin looks for; a pin without one answers to its label. */
    public function setPinName(int $pin, ?string $name): static
    {
        $this->pin($pin)->name = $name === '' ? null : $name;

        return $this;
    }

    /** The pin of a node with that name (or label, for a pin without a name); 0: none (rvn_ng_find_pin). */
    public function findPin(int $node, string $name): int
    {
        foreach ($this->pins as $p) {
            if ($p->node === $node && $p->name !== null && strcasecmp($p->name, $name) === 0) {
                return $p->id;
            }
        }
        foreach ($this->pins as $p) {
            if ($p->node === $node && $p->name === null && strcasecmp($p->label, $name) === 0) {
                return $p->id;
            }
        }

        return 0;
    }

    /** Where a pin is on the canvas: the middle of its dot. @return array{float, float} */
    public function pinPosition(int $pin): array
    {
        $this->geometry();

        return $this->pinAt($this->pin($pin));
    }

    /** The index-th input of a node, or 0. */
    public function inputAt(int $node, int $index): int
    {
        return $this->nthPin($node, $index, false);
    }

    public function outputAt(int $node, int $index): int
    {
        return $this->nthPin($node, $index, true);
    }

    private function nthPin(int $node, int $index, bool $output): int
    {
        $ids = array_values(array_map(fn (Pin $p) => $p->id, array_filter($this->pins, fn (Pin $p) => $p->node === $node && $p->output === $output)));

        return $index >= 0 ? $ids[$index] ?? 0 : 0;
    }

    /** The colour of a type's pins and of the links between them; null: one picked from its name. */
    public function setTypeColor(string $type, ?Color $color): static
    {
        $type === '' && throw new \InvalidArgumentException('A type needs a name.');
        $this->types[$this->type($type)]['color'] = $color;

        return $this->invalidatePaint();
    }

    /** An input that keeps every link made to it instead of one; pulling a wire from it starts a new one. */
    public function setPinMultiple(int $pin, bool $multiple = true): static
    {
        $p = $this->pin($pin);
        ! $p->output && $p->multiple = $multiple;

        return $this;
    }

    // ---------------------------------------------------------------- links

    /**
     * Links an output to an input, as the user does by dragging; the connect filter is not asked, nothing is
     * posted. @return int the link, or 0 when the pins cannot be linked (two inputs, the same node, other types)
     */
    public function connect(int $output, int $input): int
    {
        [$from, $to] = [$this->pin($output), $this->pin($input)];
        if (! $from->output || $to->output || ! $from->canLink($to)) {
            return 0;
        }

        return $this->addLink($output, $input, true);
    }

    public function disconnect(int $link): static
    {
        foreach ($this->links as $i => $l) {
            $l->id === $link && $this->removeLinkAt($i);
        }

        return $this;
    }

    public function linkCount(): int
    {
        return count($this->links);
    }

    public function linkAt(int $index): int
    {
        return $this->links[$index]->id ?? 0;
    }

    public function linkOutput(int $link): int
    {
        return $this->linkOf($link)?->from ?? 0;
    }

    public function linkInput(int $link): int
    {
        return $this->linkOf($link)?->to ?? 0;
    }

    /** The link that arrives at an input, or 0. */
    public function inputLink(int $input): int
    {
        return $this->inputLinkOf($input)?->id ?? 0;
    }

    // ---------------------------------------------------------------- view and selection

    /** What the middle of the widget shows: a point of the canvas, and how large (1: a unit per logical unit). */
    public function setView(float $centerX, float $centerY, float $zoom): static
    {
        $zoom > 0 || throw new \InvalidArgumentException('The zoom must be positive.');
        $this->framePending = false;
        $this->view->set($centerX, $centerY, $zoom, false);
        $this->placeContents();

        return $this->invalidatePaint();
    }

    /** @return array{float, float, float} centre x, centre y, zoom: the view asked for */
    public function view(): array
    {
        return [...$this->view->target(), $this->view->zoom];
    }

    /** A point of the widget (from its corner) on the canvas, with the view as drawn. @return array{float, float} */
    public function toCanvas(float $x, float $y): array
    {
        return $this->view->toCanvas($x, $y);
    }

    /** @return array{float, float} */
    public function fromCanvas(float $x, float $y): array
    {
        return $this->view->fromCanvas($x, $y);
    }

    /** Moves and zooms so that every node shows, gliding there. */
    public function frameAll(): static
    {
        $this->frameAllNow(true);

        return $this->invalidatePaint();
    }

    public function setGridVisible(bool $visible = true): static
    {
        $this->gridHidden = ! $visible;

        return $this->invalidatePaint();
    }

    /** The pulses along the links: true all the time, false never, null while the pointer is over the graph. */
    public function setFlowVisible(?bool $visible): static
    {
        $this->flow = $visible;

        return $this->invalidatePaint();
    }

    /** The user can look but not move, link or delete. */
    public function setReadOnly(bool $readOnly = true): static
    {
        $this->readOnly = $readOnly;

        return $this;
    }

    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    /** The look the graph starts from (Auto: the theme's family). */
    public function setPreset(CanvasPreset $preset): static
    {
        $this->preset = $preset;
        $this->changed();

        return $this;
    }

    public function preset(): CanvasPreset
    {
        return $this->preset;
    }

    public function selectNode(int $node, bool $add = false): static
    {
        $n = $this->node($node);
        ! $add && $this->selectOnly(0, 0);
        $n->selected = true;

        return $this->invalidatePaint();
    }

    public function clearSelection(): static
    {
        $this->selectOnly(0, 0);

        return $this;
    }

    public function isNodeSelected(int $node): bool
    {
        return $this->nodeOf($node)?->selected ?? false;
    }

    public function selectionCount(): int
    {
        return count(array_filter($this->nodes, fn (Node $n) => $n->selected));
    }

    /** The index-th selected node, in paint order; 0 past the end. */
    public function selectedNode(int $index): int
    {
        return array_values(array_filter($this->nodes, fn (Node $n) => $n->selected))[$index]->id ?? 0;
    }

    /** Only that node and that link are selected (0: none); whether the selection changed (rvn_ng_select_only). */
    private function selectOnly(int $node, int $link): bool
    {
        $changed = false;
        foreach ($this->nodes as $n) {
            $changed = $changed || $n->selected !== ($n->id === $node);
            $n->selected = $n->id === $node;
        }
        foreach ($this->links as $l) {
            $changed = $changed || $l->selected !== ($l->id === $link);
            $l->selected = $l->id === $link;
        }
        $changed && $this->invalidatePaint();

        return $changed;
    }

    /**
     * Asked before the user links an output to an input that may be linked: false refuses (a loop, a rule of the
     * application). Raven's connect callback; mail cannot answer, so this is a closure.
     *
     * @param  (\Closure(int $output, int $input): bool)|null  $filter
     */
    public function setConnectFilter(?\Closure $filter): static
    {
        $this->connectFilter = $filter;

        return $this;
    }

    /**
     * Delete removes the selected nodes too (those that are deletable), each posting NodeRemoved first; without it
     * Delete removes only links (Raven does it only with a removed callback set; this is that choice). Off by default.
     */
    public function setRemovable(bool $removable = true): static
    {
        $this->removable = $removable;

        return $this;
    }
}
