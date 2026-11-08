<?php

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Core\Layout\Column;
use Silk\Core\Layout\Spacer;
use Silk\Core\Tree\Tree;
use Silk\Looks\BuiltInThemes;
use Silk\Looks\LookManager;
use Silk\Looks\RavenDark;
use Silk\Widgets\Dropdown;
use Silk\Widgets\ScrollBars;
use Silk\Widgets\ScrollBox;
use Silk\Widgets\TextInput;
use Surface\Contracts\HumanInput\MouseButton;
use Venusian\Silk\Tests\Support\SpyPainter;

/*
 * Helpers of the look and family tests (Lane A). Loaded from Pest.php: a test file declares no global function.
 * Class names are written in full here, as the test files import them.
 */

/** A SpinBox 'n' (0..100, step 5, value 50) in a built-in look, focused (without the ring). */
function spinIn(string $look): object
{
    $ui = spinUi();
    $ui->tree->look = Silk\Looks\BuiltInThemes::register(new Silk\Looks\LookManager, 'sans-serif', 'monospace')->use($look)->current();
    $ui->tree->layout(new Silk\Contracts\Geometry\Size(300, 300));

    return $ui;
}

function spinMouse(object $ui, EventType $type, float $x, float $y, bool $shift = false): void
{
    $ui->router->dispatch(new SilkEvent($type, x: $x, y: $y, button: MouseButton::LEFT, clicks: 1,
        modifiers: $shift ? new Surface\Contracts\HumanInput\Modifiers(shift: true) : null));
}

/** The tree's look swapped for a built-in one, outright. */
function inLook(object $ui, string $name): void
{
    $ui->tree->look = Silk\Looks\BuiltInThemes::register(new Silk\Looks\LookManager, 'sans-serif', 'monospace')->use($name)->current();
}

/** @return list<array{string, list<mixed>}> a button's paint calls, colours as hex, in the given look and state */
function buttonPaint(string $look, ?callable $prepare = null): array
{
    $ui = silkUi(fn ($root) => $root->button('ok', 'OK'));
    inLook($ui, $look);
    $ok = $ui->root->children()[0];
    $prepare && $prepare($ok);
    $p = new SpyPainter;
    $ok->paint($p);

    return array_map(fn ($c) => [$c[0], array_map(fn ($a) => $a instanceof Surface\NutsAndBolts\Color ? $a->toHex() : $a, $c[1])], $p->calls);
}

/** A TextInput 'name' in a built-in look; $state as in the identity test. */
function fieldIn(string $look, string $state = 'blank'): array
{
    $ui = silkUi(fn ($root) => $root->add(new TextInput('name', '', 'Your name')));
    $ui->tree->look = Silk\Looks\BuiltInThemes::register(new Silk\Looks\LookManager, 'sans-serif', 'monospace')->use($look)->current();
    $ui->tree->layout(new Silk\Contracts\Geometry\Size(300, 300));
    $input = $ui->root->children()[0];
    match ($state) {
        'disabled' => $input->setEnabled(false),
        'focusing' => (fn () => $this->focusSpring->snap(0.5))->call($input),
        default => null,
    };

    return [$input, paintSnapshot($input), $ui->tree->look];
}

/** The strings a painter drew. @return list<string> */
function inputTexts(SpyPainter $p): array
{
    return array_values(array_map(fn ($c) => $c[1][0], array_filter($p->calls, fn ($c) => $c[0] === 'text')));
}

/** A shown tooltip in a built-in look (null: RavenDark as silkUi builds it), settled. */
function tipIn(?string $look = null): object
{
    $ui = tipUi();
    if ($look !== null) {
        $ui->tree->look = Silk\Looks\BuiltInThemes::register(new Silk\Looks\LookManager, 'sans-serif', 'monospace')->use($look)->current();
    }
    [$x, $y] = centre($ui->ok);
    hoverAt($ui, $x, $y);
    wait($ui, 2.0);
    $ui->tip = $ui->tree->tooltip();
    $ui->point = [$x, $y];

    return $ui;
}

/** The Dropdown 'size' in a built-in look, laid out; its paint calls and the look. */
function dropIn(string $look, ?callable $prepare = null): array
{
    $ui = dropUi();
    $ui->tree->look = Silk\Looks\BuiltInThemes::register(new Silk\Looks\LookManager, 'sans-serif', 'monospace')->use($look)->current();
    $ui->tree->layout(new Size(300, 300));
    $prepare && $prepare($ui);
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $ui->drop->paint($p);

    return [$ui, $p->calls, $ui->tree->look];
}

/** The open list of the Dropdown 'size' (or one with $options) in a built-in look, settled; its surface and row paint calls. */
function openListIn(string $look, ?array $options = null): array
{
    $ui = silkUi(fn ($root) => $root->add(new Dropdown('size', $options ?? ['Small', 'Medium', 'Large'], 1)));
    $ui->drop = $ui->root->children()[0];
    $ui->tree->look = Silk\Looks\BuiltInThemes::register(new Silk\Looks\LookManager, 'sans-serif', 'monospace')->use($look)->current();
    $ui->tree->layout(new Size(300, 300));
    click($ui->router, ...centre($ui->drop));
    settleDrop($ui);
    $list = $ui->drop->list();
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    (fn () => $this->paintSelf($p))->call($list);

    return [$ui, $list, $p->calls, $ui->tree->look];
}

function barLook(string $name): Silk\Contracts\Look\Look
{
    return BuiltInThemes::register(new LookManager, 'sans-serif', 'monospace')->use($name)->current();
}

/** @return list<array{string, list<mixed>}> */
function barCalls(string $look, bool $hot = false, float $visible = 1.0, float $wide = 0.0): array
{
    $p = new SpyPainter;
    ScrollBars::paint($p, barLook($look), new Rect(90, 0, 10, 100), true, 20.0, 40.0, $hot, $visible, $wide);

    return $p->calls;
}

/** A 200 x 100 ScrollBox over a 150 x 400 Spacer, in a built-in look, laid out and painted. */
function barBox(string $look): object
{
    $box = new ScrollBox('box');
    $box->add($content = new Spacer('content', 150, 400));
    $tree = new Tree($box, Silk\Looks\BuiltInThemes::register(new Silk\Looks\LookManager, 'sans-serif', 'monospace')->use($look)->current());
    $tree->layout(new Size(200, 100));
    $p = new SpyPainter;
    $box->paint($p);

    return (object) ['box' => $box, 'content' => $content, 'tree' => $tree, 'calls' => $p->calls];
}

function propsLook(array $styles): Silk\Contracts\Look\Look
{
    return (new LookManager)
        ->extend('base', fn () => RavenDark::theme())
        ->extend('t', fn () => ['look' => 'base', 'styles' => $styles])
        ->use('t')->current();
}

/** @return array{LookManager, Tree, Column} a painted tree watched by the manager, in raven-dark */
function switching(): array
{
    $m = BuiltInThemes::register(new LookManager, 'sans-serif', 'monospace')->use('raven-dark');
    $root = new Column('window');
    $tree = new Tree($root, $m->current());
    $m->watch($tree);
    $tree->layout(new Size(200, 100));
    $tree->paintAll(new SpyPainter);

    return [$m, $tree, $root];
}

/** An open menu (Cut Cmd+X, a separator, Wrap checked and checkable, More >) in a built-in look, Cut highlighted; its paint calls with the children's. */
function menuIn(string $look): array
{
    $ui = silkUi(fn ($root) => $root->button('anchor', 'Anchor'));
    $ui->tree->look = BuiltInThemes::register(new LookManager, 'sans-serif', 'monospace')->use($look)->current();
    $menu = (new Silk\Widgets\Menu('edit'))->in($ui->tree);
    $menu->item('cut', 'Cut', 'Cmd+X');
    $menu->separator();
    $menu->item('wrap', 'Wrap')->setCheckable(true)->setChecked(true);
    $menu->submenu('more', 'More')->item('x', 'X');
    $menu->openAt(10, 10);
    $ui->tree->advance(5.0);
    $ui->tree->layout(new Size(300, 300));
    $menu->highlightItem($menu->children()[0]);
    $p = new SpyPainter;
    $menu->render($p);

    return [$ui, $menu, $p->calls, $ui->tree->look];
}

/** A checkbox 'c' ("Check") in a built-in look, its springs settled after $prepare; its paint calls, colours as hex. */
function checkIn(string $look, ?callable $prepare = null): array
{
    $ui = silkUi(fn ($root) => $root->checkbox('c', 'Check'));
    inLook($ui, $look);
    $ui->tree->layout(new Size(300, 300));
    $c = $ui->root->children()[0];
    $prepare && $prepare($c);
    foreach ($c->springs() as $s) {
        $s->finish();
    }
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $c->paint($p);

    return [$c, array_map(fn ($x) => [$x[0], array_map(fn ($a) => $a instanceof Surface\NutsAndBolts\Color ? $a->toHex() : $a, $x[1])], $p->calls), $ui->tree->look];
}

/** A toggle 'wifi' in a built-in look, its springs settled after $prepare; its paint calls, colours as hex. */
function toggleIn(string $look, ?callable $prepare = null): array
{
    $ui = silkUi(fn ($root) => $root->toggle('wifi'));
    inLook($ui, $look);
    $ui->tree->layout(new Size(300, 300));
    $sw = $ui->root->children()[0];
    $prepare && $prepare($sw);
    foreach ($sw->springs() as $s) {
        $s->finish();
    }
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $sw->paint($p);

    return [$sw, array_map(fn ($x) => [$x[0], array_map(fn ($a) => $a instanceof Surface\NutsAndBolts\Color ? $a->toHex() : $a, $x[1])], $p->calls), $ui->tree->look];
}

/** A radio 'r' in a built-in look, settled; its paint calls, colours as hex. */
function radioIn(string $look, bool $checked = false): array
{
    $ui = silkUi(fn ($root) => $root->radio('r', 'Radio', $checked));
    inLook($ui, $look);
    $ui->tree->layout(new Size(300, 300));
    $r = $ui->root->children()[0];
    foreach ($r->springs() as $s) {
        $s->finish();
    }
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $r->paint($p);

    return [$r, array_map(fn ($x) => [$x[0], array_map(fn ($a) => $a instanceof Surface\NutsAndBolts\Color ? $a->toHex() : $a, $x[1])], $p->calls), $ui->tree->look];
}

/** A Slider 'zoom' 0..100 at 40, in the default look. */
function sliderUi(): object
{
    $ui = silkUi(fn ($root) => $root->add(new Silk\Widgets\Slider('zoom', 0, 100, 40)));
    $ui->slider = $ui->root->children()[0];

    return $ui;
}

/** A Slider 0..100 at 40 in a built-in look, its springs settled after $prepare; its paint calls, colours as hex. */
function sliderIn(string $look, ?callable $prepare = null): array
{
    $ui = sliderUi();
    inLook($ui, $look);
    $ui->tree->layout(new Size(300, 300));
    $prepare && $prepare($ui->slider);
    foreach ($ui->slider->springs() as $s) {
        $s->finish();
    }
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $ui->slider->paint($p);

    return [$ui->slider, array_map(fn ($x) => [$x[0], array_map(fn ($a) => $a instanceof Surface\NutsAndBolts\Color ? $a->toHex() : $a, $x[1])], $p->calls), $ui->tree->look];
}

/** A ProgressBar in a built-in look, settled after $prepare, laid out $height high; its paint calls, colours as hex. */
function barIn(string $look, float $fraction, ?callable $prepare = null, float $height = 8.0): array
{
    $ui = silkUi(fn ($root) => $root->progressBar('load', $fraction));
    inLook($ui, $look);
    $bar = $ui->root->children()[0];
    $bar->arrange(new Rect(10, 10, 200, $height));
    $prepare && $prepare($bar);
    foreach ($bar->springs() as $s) {
        $s->finish();
    }
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $bar->paint($p);

    return [$bar, array_map(fn ($x) => [$x[0], array_map(fn ($a) => $a instanceof Surface\NutsAndBolts\Color ? $a->toHex() : $a, $x[1])], $p->calls), $ui->tree->look];
}

/** A TabView with pages general, advanced, about; settled. */
function tabUi(): object
{
    $ui = silkUi(fn ($root) => $root->add(new Silk\Widgets\TabView('tabs')));
    $ui->tabs = $ui->root->children()[0];
    $ui->tabs->addPage('general', 'General')->add(new Silk\Core\Layout\Spacer('a', 100, 40));
    $ui->tabs->addPage('advanced', 'Advanced')->add(new Silk\Core\Layout\Spacer('b', 100, 80));
    $ui->tabs->addPage('about', 'About');
    settleAll($ui);

    return $ui;
}


/** tabUi in a built-in look, settled after $prepare; the TabView's own paint calls, colours as hex. */
function tabIn(string $look, ?callable $prepare = null): array
{
    $ui = tabUi();
    inLook($ui, $look);
    $ui->tree->layout(new Size(400, 300));
    $prepare && $prepare($ui->tabs);
    $ui->tree->layout(new Size(400, 300));
    settleAll($ui);
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    (fn () => $this->paintSelf($p))->call($ui->tabs);

    return [$ui->tabs, array_map(fn ($x) => [$x[0], array_map(fn ($a) => $a instanceof Surface\NutsAndBolts\Color ? $a->toHex() : $a, $x[1])], $p->calls), $ui->tree->look];
}

/** An Expander with a 100 x 40 body in a built-in look, settled; its paint calls, colours as hex. */
function expanderIn(string $look, bool $open = false): array
{
    $ui = silkUi(fn ($root) => $root->add(new Silk\Widgets\Expander('more', 'More', $open)));
    inLook($ui, $look);
    $x = $ui->root->children()[0];
    $x->body()->add(new Silk\Core\Layout\Spacer('b', 100, 40));
    $ui->tree->layout(new Size(300, 300));
    settleAll($ui);
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $x->paint($p);

    return [$x, array_map(fn ($c) => [$c[0], array_map(fn ($a) => $a instanceof Surface\NutsAndBolts\Color ? $a->toHex() : $a, $c[1])], $p->calls), $ui->tree->look];
}

/** A documents TabView 'docs' 400 wide in a built-in look: Level (fixed), Hero, Rock; settled. */
function docsUi(string $look = 'raven-dark', int $extra = 0): object
{
    $ui = silkUi(fn ($root) => $root->add((new Silk\Widgets\TabView('docs'))->setDocuments(true)));
    inLook($ui, $look);
    $ui->tabs = $ui->root->children()[0];
    $ui->tabs->addPage('level', 'Level');
    $ui->tabs->setTabClosable('level', false);
    $ui->tabs->addPage('hero', 'Hero');
    $ui->tabs->addPage('rock', 'Rock');
    for ($i = 0; $i < $extra; $i++) {
        $ui->tabs->addPage("asset-{$i}", "Asset {$i}");
    }
    $ui->tabs->arrange(new Rect(0, 0, 400, 200));
    settleAll($ui);
    $ui->tabs->arrange(new Rect(0, 0, 400, 200));

    return $ui;
}

/** A Down then an Up of $button at a point. */
function buttonClick(object $ui, MouseButton $button, float $x, float $y): void
{
    $ui->router->dispatch(new SilkEvent(EventType::MouseDown, x: $x, y: $y, button: $button, clicks: 1));
    $ui->router->dispatch(new SilkEvent(EventType::MouseUp, x: $x, y: $y, button: $button, clicks: 1));
}

/** The TabView's own paint calls, colours as hex. */
function docsPaint(object $ui): array
{
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    (fn () => $this->paintSelf($p))->call($ui->tabs);

    return array_map(fn ($c) => [$c[0], array_map(fn ($a) => $a instanceof Surface\NutsAndBolts\Color ? $a->toHex() : $a, $c[1])], $p->calls);
}

/** A 600 x 400 DockArea 'dock' with files | editor (output under editor), settled. */
function dockUi(string $look = 'raven-dark'): object
{
    $ui = silkUi(fn ($root) => $root->add(new Silk\Widgets\DockArea('dock')));
    inLook($ui, $look);
    $ui->dock = $ui->root->children()[0];
    $ui->dock->addPanel(new Silk\Core\Layout\Spacer('editor', 10, 10), 'Editor');
    $ui->dock->addPanel(new Silk\Core\Layout\Spacer('files', 10, 10), 'Files', Silk\Widgets\Docking\DockSide::Left, 'editor', 0.25);
    $ui->dock->addPanel(new Silk\Core\Layout\Spacer('output', 10, 10), 'Output', Silk\Widgets\Docking\DockSide::Bottom, 'editor', 0.3);
    dockSettle($ui);

    return $ui;
}

function dockSettle(object $ui): void
{
    for ($i = 0; $i < 3; $i++) {
        $ui->dock->arrange(new Rect(0, 0, 600, 400));
        foreach ($ui->dock->springs() as $s) {
            $s->finish();
        }
        $ui->dock->arrange(new Rect(0, 0, 600, 400));
    }
}

/** The centre of a panel's tab (its group's bar). */
function dockTab(object $ui, string $panel): array
{
    $g = $ui->dock->root()->find($panel)[0];
    $shown = $g->shown();
    $tab = (fn () => $this->tabRect($g, $shown, array_search($panel, $g->panels, true)))->call($ui->dock);

    return [$tab->x + 12, $tab->y + $tab->h / 2];
}

/** A press at $from, moves to $to in steps, a release there. */
function dockDrag(object $ui, array $from, array $to): void
{
    $ui->router->dispatch(new SilkEvent(EventType::MouseDown, x: $from[0], y: $from[1], button: MouseButton::LEFT, clicks: 1));
    foreach ([0.25, 0.5, 0.75, 1.0] as $t) {
        $ui->router->dispatch(new SilkEvent(EventType::MouseMove, x: $from[0] + ($to[0] - $from[0]) * $t, y: $from[1] + ($to[1] - $from[1]) * $t));
    }
    $ui->router->dispatch(new SilkEvent(EventType::MouseUp, x: $to[0], y: $to[1], button: MouseButton::LEFT, clicks: 1));
}

/** An Island 'island' at the top of a column (folded: a label; unfolded: a 200 x 80 spacer), then a button 'away'. */
function islandUi(string $look = 'raven-light'): object
{
    $ui = silkUi(function ($root) {
        $root->add($island = new Silk\Widgets\Island('island'));
        $island->add(new Silk\Widgets\Label('fps', '60 fps'));
        $island->add(new Silk\Core\Layout\Spacer('panel', 200, 80));
        $root->button('away', 'Away');
    });
    inLook($ui, $look);
    $ui->island = $ui->root->children()[0];
    $ui->tree->layout(new Size(400, 400));
    settleAll($ui);

    return $ui;
}

function islandPointer(object $ui, EventType $type): void
{
    [$x, $y] = centre($ui->island);
    $ui->router->dispatch(new SilkEvent($type === EventType::Leave ? EventType::MouseMove : $type, x: $type === EventType::Leave ? 390 : $x, y: $type === EventType::Leave ? 390 : $y));
}

/** A NodeGraph filling a 600 x 400 window, in $look: Colour (out colour) and Mix (in a, in b colours; out). */
function nodeGraphUi(string $look = 'raven-dark'): object
{
    $ui = silkUi(fn ($root) => $root->add(new Silk\Widgets\NodeGraph('graph'), new Silk\Contracts\Widget\Slot(grow: 1)));
    inLook($ui, $look);
    $g = $ui->g = $ui->root->children()[0];
    $ui->colour = $g->addNode('Colour', 40, 60);
    $ui->out = $g->addOutput($ui->colour, 'Colour', 'color');
    $ui->mix = $g->addNode('Mix', 320, 90);
    $ui->a = $g->addInput($ui->mix, 'A', 'color');
    $ui->b = $g->addInput($ui->mix, 'B', 'color');
    $ui->result = $g->addOutput($ui->mix, 'Result', 'color');
    $ui->tree->layout(new Size(600, 400));

    return $ui;
}

/** The graph's paint calls once its springs have settled (a second at most), laid out at 600 x 400. */
function nodeGraphPaint(object $ui): Venusian\Silk\Tests\Support\SpyPainter
{
    $ui->tree->layout(new Size(600, 400));
    $p = new SpyPainter;
    $ui->g->render($p);
    for ($i = 0; $i < 60 && $ui->tree->advance(1 / 60); $i++) {
    }
    $ui->tree->layout(new Size(600, 400));
    $p = new SpyPainter;
    $ui->g->render($p);

    return $p;
}

/** A canvas point of the graph as a point of the window. @return array{float, float} */
function ngAt(object $ui, float $cx, float $cy): array
{
    $b = $ui->g->bounds();
    [$x, $y] = $ui->g->fromCanvas($cx, $cy);

    return [$b->x + $x, $b->y + $y];
}

/** A pin's dot as a point of the window. @return array{float, float} */
function ngPin(object $ui, int $pin): array
{
    return ngAt($ui, ...$ui->g->pinPosition($pin));
}

function ngMouse(object $ui, EventType $type, array $at, ?MouseButton $button = MouseButton::LEFT, int $clicks = 1, ?Surface\Contracts\HumanInput\Modifiers $mods = null): void
{
    $ui->router->dispatch(new SilkEvent($type, x: $at[0], y: $at[1], button: $type === EventType::MouseMove ? null : $button, clicks: $clicks, modifiers: $mods));
}

/** Presses at $from, moves through $via, lets go at $to. */
function ngDrag(object $ui, array $from, array $to, array $via = [], ?MouseButton $button = MouseButton::LEFT, ?Surface\Contracts\HumanInput\Modifiers $mods = null): void
{
    ngMouse($ui, EventType::MouseDown, $from, $button, 1, $mods);
    foreach ([...$via, $to] as $at) {
        ngMouse($ui, EventType::MouseMove, $at);
    }
    ngMouse($ui, EventType::MouseUp, $to, $button, 1, $mods);
}

function ngKey(object $ui, Surface\Contracts\HumanInput\Key $key, ?Surface\Contracts\HumanInput\Modifiers $mods = null): void
{
    $ui->router->dispatch(new SilkEvent(EventType::KeyDown, key: $key, modifiers: $mods));
    $ui->router->dispatch(new SilkEvent(EventType::KeyUp, key: $key, modifiers: $mods));
}

/** The mail posted so far, as class short name => list of the fields after the path. */
function ngMail(object $ui): array
{
    return array_map(fn ($m) => (new ReflectionClass($m))->getShortName(), $ui->loop->posted);
}

/** A graph of the helper's two nodes, laid out and painted once so that it glides. */
function nodeGraphLive(string $look = 'raven-dark'): object
{
    $ui = nodeGraphUi($look);
    nodeGraphPaint($ui);
    $ui->loop->posted = [];

    return $ui;
}

/** The contrast ratio of the text $title with what lies under it, as the widget paints in the tree's look. */
function inkContrast(object $ui, Silk\Core\Tree\Widget $w, string $title): float
{
    $ui->tree->reducedMotion = true;
    $ui->tree->layout(new Silk\Contracts\Geometry\Size(300, 600));
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $w->render($p);
    $look = $ui->tree->look;
    $under = Silk\Painter\RavenPaint::over($look->color(Silk\Contracts\Look\Role::Window), new Surface\NutsAndBolts\Color(0.5, 0.5, 0.5));
    [$ink, $at] = [null, null];
    foreach ($p->calls as [$method, $a]) {
        if ($method === 'text' && $a[0] === $title) {
            [$ink, $at] = [$a[3], [$a[1] + 2, $a[2] - 4]];
        }
    }
    $fill = $under;
    foreach ($p->calls as [$method, $a]) {
        $method === 'rect' && $a[1]->alpha > 0.02 && $at[0] >= $a[0]->x && $at[0] <= $a[0]->x + $a[0]->w && $at[1] >= $a[0]->y && $at[1] <= $a[0]->y + $a[0]->h
            && $fill = Silk\Painter\RavenPaint::over($a[1], $fill);
    }

    return Silk\Painter\RavenPaint::contrast(Silk\Painter\RavenPaint::over($ink, $fill), $fill);
}
