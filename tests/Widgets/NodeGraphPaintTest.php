<?php

use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Looks\Canvas\CanvasPreset;
use Silk\Widgets\Nodes\Wire;

/** @return list<array{string, list<mixed>}> the calls of one kind */
function callsOf(Venusian\Silk\Tests\Support\SpyPainter $p, string $method): array
{
    return array_values(array_filter($p->calls, fn ($c) => $c[0] === $method));
}

it('paints the canvas as an Input box with a Border, then a grid of dots 22 apart at 0.12, every fourth stronger, in two fills (node_graph.c:1050-1071, 1568-1606)', function () {
    $ui = nodeGraphUi();
    $p = nodeGraphPaint($ui);
    $look = $ui->tree->look;
    $b = $ui->g->bounds();
    $canvas = $p->calls[0][1];
    $text = $look->color(Role::Text);
    [$minor, $strong] = array_slice(callsOf($p, 'fill'), 0, 2);
    $contours = function (Surface\NutsAndBolts\Path $path): array {
        $out = [];
        foreach ($path->ops() as $op) {
            $op[0] === 'M' && $out[] = [];
            $op[0] !== 'Z' && $out[count($out) - 1][] = [$op[1], $op[2]];
        }

        return $out;
    };
    $dots = $contours($minor[1][0]);
    $big = $contours($strong[1][0]);
    $extent = fn (array $c) => [min(array_column($c, 0)), max(array_column($c, 0)), min(array_column($c, 1)), max(array_column($c, 1))];
    [$x0, $x1, $y0, $y1] = $extent($dots[0]);
    [$X0, $X1] = $extent($big[0]);

    expect([$canvas[0], $canvas[1]->toHex(), $canvas[2], $canvas[3]->toHex()])->toBe([$b, $look->color(Role::Input)->toHex(), $look->metric(Metric::CornerRadius), $look->color(Role::Border)->toHex()])
        // 600 x 400 at 22: about 28 x 19 dots; a sixteenth of them are strong
        ->and(count($dots))->toBeGreaterThan(400)->and(count($big))->toBeGreaterThan(20)->toBeLessThan(count($dots) / 8)
        // a circle of radius 1 (1.5 strong) about a whole unit, flattened to straight sides
        ->and([round($x1 - $x0, 3), round($y1 - $y0, 3), round($X1 - $X0, 3)])->toBe([2.0, 2.0, 3.0])
        ->and([($x0 + $x1) / 2 === floor(($x0 + $x1) / 2), ($y0 + $y1) / 2 === floor(($y0 + $y1) / 2)])->toBe([true, true])
        ->and(count($dots[0]))->toBeGreaterThan(5)
        ->and(array_map(fn ($c) => round($c[1][1]->alpha, 2), [$minor, $strong]))->toBe([round($text->alpha * 0.12, 2), round($text->alpha * 0.24, 2)]);
});

it('records the grid in a few commands, and draws the same path again while the view stays', function () {
    $ui = nodeGraphUi();
    $count = fn () => count(callsOf(nodeGraphPaint($ui), 'fill'));
    $first = nodeGraphPaint($ui);
    $second = nodeGraphPaint($ui);

    expect(callsOf($first, 'fill')[0][1][0])->toBe(callsOf($second, 'fill')[0][1][0])
        ->and(count($first->calls))->toBeLessThan(200);
    $ui->g->setGridVisible(false);
    expect($count())->toBe(0);
});

it('draws a link as a cubic from its output to its input: out straight, back in straight, hanging a little (node_graph.c:642-659)', function () {
    $ui = nodeGraphUi();
    $ui->g->connect($ui->out, $ui->a);
    $p = nodeGraphPaint($ui);
    [$ax, $ay] = $ui->g->pinPosition($ui->out);
    [$bx, $by] = $ui->g->pinPosition($ui->a);
    $ops = callsOf($p, 'stroke')[0][1][0]->ops();
    $curve = Wire::curve($ax, $ay, $bx, $by, 0, 0);

    expect($ops[0])->toBe(['M', $ax, $ay])
        ->and(array_map(fn ($v) => round($v, 6), array_slice($ops[1], 1)))->toBe(array_map(fn ($v) => round($v, 6), array_slice($curve, 2)))
        ->and($curve[3])->toBeGreaterThan($ay);
});

it('draws a link in the colour of its pins, 2.2 wide in RavenDark, with light around it in a blurred layer (node_graph.c:1158-1164, 1458-1495)', function () {
    $ui = nodeGraphUi();
    $ui->g->connect($ui->out, $ui->a);
    $p = nodeGraphPaint($ui);
    $layer = callsOf($p, 'layer')[0][1];
    $strokes = callsOf($p, 'stroke');

    // the halo: 3 + 8 x 0.15 wide around the stroke, as bright as 0.18 + 0.4 x 0.15
    expect(round($layer[1], 4))->toBe(0.24)
        ->and($layer[3]->steps[0])->toBeInstanceOf(Surface\Contracts\Drawing\Effects\Blur::class)
        ->and(round($strokes[0][1][2]->width, 4))->toBe(round(2.2 + 4.2, 4))
        ->and($strokes[1][1][2]->width)->toBe(2.2)
        ->and($strokes[1][1][1]->toHex())->toBe($ui->g->pinColor($ui->out)->toHex());
});

it('shades a wire from the colour of one pin to the other\'s along its length', function () {
    $ui = nodeGraphUi('macos-dark');
    $any = $ui->g->addInput($ui->mix, 'Any');
    $ui->g->connect($ui->out, $any);
    $strokes = callsOf(nodeGraphPaint($ui), 'stroke');

    expect(count($strokes))->toBeGreaterThan(10)
        ->and($strokes[0][1][1]->toHex())->not->toBe(end($strokes)[1][1]->toHex())
        ->and(abs($strokes[0][1][1]->red - $ui->g->pinColor($ui->out)->red))->toBeLessThan(0.05)
        ->and(abs(end($strokes)[1][1]->red - $ui->g->pinColor($any)->red))->toBeLessThan(0.05);
});

it('runs pulses of light along the wires while the flow shows: bright stretches in blurred layers (node_graph.c:1128-1132, ui.frag:292-297)', function () {
    $ui = nodeGraphUi('macos-dark');
    $ui->g->connect($ui->out, $ui->a);
    $quiet = count(callsOf(nodeGraphPaint($ui), 'layer'));
    $ui->g->setFlowVisible(true);
    $layers = callsOf(nodeGraphPaint($ui), 'layer');

    expect($quiet)->toBe(0)->and(count($layers))->toBeGreaterThan(0)
        ->and($layers[0][1][3]->steps[0])->toBeInstanceOf(Surface\Contracts\Drawing\Effects\Blur::class);
});

/** A graph whose pulses run, settled and painted, with nothing waiting to repaint: only the next tick will ask. */
function pulsingGraph(): object
{
    $ui = nodeGraphUi('macos-dark');
    $ui->g->connect($ui->out, $ui->a);
    $ui->g->setFlowVisible(true);
    nodeGraphPaint($ui);
    $ui->g->markPainted();

    return $ui;
}

it('paints only where the glints are on a frame the pulses alone asked for, and those layers lie inside it', function () {
    $ui = pulsingGraph();
    $full = $ui->g->bounds()->grow(Silk\Core\Tree\Widget::OVERDRAW);
    $ui->tree->advance(1 / 60);
    $extent = $ui->g->paintExtent();
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $ui->g->render($p);
    $layers = callsOf($p, 'layer');
    $inside = true;
    foreach ($layers as $layer) {
        $box = $layer[1][0];
        [$x0, $y0] = ngAt($ui, $box->x, $box->y);
        [$x1, $y1] = ngAt($ui, $box->x + $box->w, $box->y + $box->h);
        $inside = $inside && $x0 >= $extent->x - 1e-6 && $y0 >= $extent->y - 1e-6 && $x1 <= $extent->x + $extent->w + 1e-6 && $y1 <= $extent->y + $extent->h + 1e-6;
    }

    expect($layers)->not->toBe([])->and($inside)->toBeTrue()
        ->and($extent->w * $extent->h)->toBeLessThan($full->w * $full->h / 2);
});

it('follows the glints from tick to tick, and goes back to the whole graph when anything else repaints', function () {
    $ui = pulsingGraph();
    $full = $ui->g->bounds()->grow(Silk\Core\Tree\Widget::OVERDRAW);
    $ui->tree->advance(1 / 60);
    $first = $ui->g->paintExtent();
    $ui->g->markPainted();
    for ($i = 0; $i < 20; $i++) {
        $ui->tree->advance(1 / 60);
    }
    $later = $ui->g->paintExtent();
    $ui->g->invalidatePaint();

    expect($later)->not->toEqual($first)
        ->and($ui->g->paintExtent())->toEqual($full);
});

it('repaints a wire\'s halo whole, so its blur never meets the edge of the region', function () {
    $ui = pulsingGraph();
    $ui->g->setTypeStyle('color', 'LinkGlow', '1');
    nodeGraphPaint($ui);
    $ui->g->markPainted();
    $ui->tree->advance(1 / 60);
    $extent = $ui->g->paintExtent();
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $ui->g->render($p);
    $layers = callsOf($p, 'layer');
    $halos = array_values(array_filter($layers, fn ($c) => $c[1][2] < 1.0 && $c[1][1] instanceof Closure));
    $outside = 0;
    foreach ($layers as $layer) {
        $box = $layer[1][0];
        [$x0, $y0] = ngAt($ui, $box->x, $box->y);
        [$x1, $y1] = ngAt($ui, $box->x + $box->w, $box->y + $box->h);
        $outside += ($x0 < $extent->x - 1e-6 || $y0 < $extent->y - 1e-6 || $x1 > $extent->x + $extent->w + 1e-6 || $y1 > $extent->y + $extent->h + 1e-6) ? 1 : 0;
    }

    expect(count($layers))->toBeGreaterThan(count($halos))->and($outside)->toBe(0);
});

it('repaints a glass look whole: a glass pane frosts what is around it', function () {
    $ui = nodeGraphUi('raven-glass-dark');
    $ui->g->connect($ui->out, $ui->a);
    $ui->g->setFlowVisible(true);
    nodeGraphPaint($ui);
    $ui->g->markPainted();
    $full = $ui->g->bounds()->grow(Silk\Core\Tree\Widget::OVERDRAW);
    $ui->tree->advance(1 / 60);

    expect($ui->g->paintExtent())->toEqual($full);
});

it('keeps the whole graph as its extent when something else asked for the frame too', function () {
    $ui = pulsingGraph();
    $full = $ui->g->bounds()->grow(Silk\Core\Tree\Widget::OVERDRAW);
    $ui->g->setNodeColor($ui->mix, Surface\NutsAndBolts\Color::hex('#FF0000'));
    $ui->tree->advance(1 / 60);

    expect($ui->g->paintExtent())->toEqual($full);
});

it('starts no glints when the pointer is over a graph built without them, and does for one built with them', function () {
    $glints = function (bool $onHover) {
        $ui = nodeGraphUi('macos-dark', $onHover);
        $ui->g->connect($ui->out, $ui->a);
        ngMouse($ui, Silk\Contracts\Input\EventType::MouseMove, ngAt($ui, 200, 150), null);

        return count(callsOf(nodeGraphPaint($ui), 'layer'));
    };

    expect($glints(true))->toBeGreaterThan(0)->and($glints(false))->toBe(0);
});

it('paints a RavenDark node as a Popup box of radius 10 over a shadow of 16, its header tinted 0.2 toward its colour, the title bold (node_graph.c:1087-1121, 1340-1405)', function () {
    $ui = nodeGraphUi();
    $ui->g->setNodeColor($ui->mix, Surface\NutsAndBolts\Color::hex('#FF0000'));
    $p = nodeGraphPaint($ui);
    $look = $ui->tree->look;
    [$x, $y] = $ui->g->nodePosition($ui->mix);
    $body = array_values(array_filter(callsOf($p, 'rect'), fn ($c) => $c[1][0]->x === $x && $c[1][0]->y === $y && $c[1][2] === 10.0));
    $titles = array_values(array_filter(callsOf($p, 'text'), fn ($c) => $c[1][0] === 'Mix'));
    $tint = Silk\Painter\RavenPaint::mix(Silk\Looks\Canvas\PartStyle::fade($look->color(Role::Popup), 1 / $look->color(Role::Popup)->alpha), Surface\NutsAndBolts\Color::hex('#FF0000'), 0.2);

    expect($body[0][1][1]->toHex())->toBe($look->color(Role::Popup)->toHex())
        ->and(callsOf($p, 'shadow')[0][1][2])->toBe(16.0)
        ->and(array_map(fn ($c) => $c[1][1]->toHex(), callsOf($p, 'rect')))->toContain($tint->toHex())
        ->and($titles[0][1][4]->weight)->toBe(700);
});

it('takes the presets\' headers: Modern a dot, Blueprint a gradient, Blender solid, Neon a stripe (node_graph.c:1123-1131)', function () {
    $paint = function (CanvasPreset $preset) {
        $ui = nodeGraphUi();
        $ui->g->setPreset($preset);

        return nodeGraphPaint($ui)->methods();
    };

    expect($paint(CanvasPreset::Blueprint))->toContain('gradient')
        ->and($paint(CanvasPreset::Modern))->not->toContain('gradient');
});

it('pops a node in when it comes after the graph showed: from 0.86 of its size (node_graph.c:1497-1500)', function () {
    $ui = nodeGraphUi();
    nodeGraphPaint($ui);
    $ui->g->addNode('Late', 100, 260);
    $ui->tree->layout(new Silk\Contracts\Geometry\Size(600, 400));
    $ui->tree->advance(1 / 60);
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $ui->g->render($p);
    $scales = array_map(fn ($c) => $c[1][0], callsOf($p, 'pushTransform'));

    // (at 0 it is not painted at all: faded out)
    expect(min($scales))->toBeGreaterThan(0.86)->toBeLessThan(1.0);
});

it('gives a node, a type and a pin styles of their own, and refuses what is not one (node_graph.c:2413-2524)', function () {
    $ui = nodeGraphUi();
    $g = $ui->g;
    $g->connect($ui->out, $ui->a);

    expect($g->setTypeStyle('color', 'LinkWidth', '5'))->toBeTrue()
        ->and($g->setNodeStyle($ui->mix, 'HeaderStyle', 'Gradient'))->toBeTrue()
        ->and($g->setPinStyle($ui->a, 'Shape', 'Diamond'))->toBeTrue()
        ->and($g->setPartStyle('GridStyle', 'Lines'))->toBeTrue()
        ->and($g->setPartStyle('Nonsense', 'x'))->toBeFalse()
        ->and($g->setNodeStyle($ui->mix, 'Glow', 'bright'))->toBeFalse();
    $p = nodeGraphPaint($ui);

    expect(callsOf($p, 'stroke')[1][1][2]->width)->toBe(5.0)
        ->and($p->methods())->toContain('gradient')
        ->and($p->methods())->toContain('triangle');
});

it('draws a glass look\'s nodes as a slab of Popup glass with no shadow of its own (node_graph.c:1461-1467)', function () {
    $ui = nodeGraphUi('raven-glass-dark');
    $glass = callsOf(nodeGraphPaint($ui), 'glass');

    expect($glass)->toHaveCount(2)->and($glass[0][1][2]->shadow)->toBe(0.0);
});

it('lets the pointer change nothing it paints with: the tree posts nothing', function () {
    $ui = nodeGraphUi();
    $ui->g->connect($ui->out, $ui->a);
    nodeGraphPaint($ui);

    expect($ui->loop->posted)->toBe([]);
});
