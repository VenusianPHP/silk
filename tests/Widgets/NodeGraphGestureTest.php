<?php

use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Mail\NodeLinked;
use Silk\Contracts\Mail\NodeMoved;
use Silk\Contracts\Mail\NodeRemoved;
use Silk\Contracts\Mail\NodeUnlinked;
use Silk\Widgets\NodeGraph;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;

it('links by dragging from an output to an input of its type: NodeLinked, then NodeGraphEdited, a ring leaves both pins (node_graph.c:1829-1866, 1951-1960)', function () {
    $ui = nodeGraphLive();
    ngDrag($ui, ngPin($ui, $ui->out), ngPin($ui, $ui->a), [ngAt($ui, 200, 100)]);

    $link = $ui->g->inputLink($ui->a);
    expect($link)->toBeGreaterThan(0)
        ->and(ngMail($ui))->toBe(['NodeLinked', 'NodeGraphEdited'])
        ->and([$ui->loop->posted[0]->link, $ui->loop->posted[0]->output, $ui->loop->posted[0]->input])->toBe([$link, $ui->out, $ui->a]);
    $ui->tree->advance(1 / 60);
    $p = nodeGraphPaint($ui);
    expect($p->methods())->toContain('stroke');
});

it('links from an input to an output just as well, and drops on a node to take its first free pin that fits (node_graph.c:270-299)', function () {
    $ui = nodeGraphLive();
    ngDrag($ui, ngPin($ui, $ui->b), ngPin($ui, $ui->out));
    expect($ui->g->linkInput($ui->g->linkAt(0)))->toBe($ui->b);

    $ui = nodeGraphLive();
    [$x, $y] = $ui->g->nodePosition($ui->mix);
    ngDrag($ui, ngPin($ui, $ui->out), ngAt($ui, $x + 70, $y + 62));
    // dropped on the node: its first input that is free
    expect($ui->g->linkInput($ui->g->linkAt(0)))->toBe($ui->a);
});

it('refuses a link of another type, of two inputs or of one node: the wire draws back (node_graph.c:325-329, 1932-1949)', function () {
    $ui = nodeGraphLive();
    $num = $ui->g->addInput($ui->g->addNode('Number', 40, 240), 'Amount', 'number');
    $ui->tree->layout(new Silk\Contracts\Geometry\Size(600, 400));
    ngDrag($ui, ngPin($ui, $ui->out), ngPin($ui, $num));
    ngDrag($ui, ngPin($ui, $ui->a), ngPin($ui, $ui->b));

    expect($ui->g->linkCount())->toBe(0)->and($ui->loop->posted)->toBe([]);
    // a ghost of the wire is drawing back, then gone
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $ui->g->render($p);
    expect(count(array_filter($p->calls, fn ($c) => $c[0] === 'stroke')))->toBeGreaterThan(0);
    $gone = nodeGraphPaint($ui);
    expect(array_filter($gone->calls, fn ($c) => $c[0] === 'stroke'))->toBe([]);
});

it('asks the filter before it links, and a no leaves no link and no mail (node_graph.c:1837-1838)', function () {
    $ui = nodeGraphLive();
    $asked = [];
    $ui->g->setConnectFilter(function (int $output, int $input) use (&$asked) {
        $asked[] = [$output, $input];

        return false;
    });
    ngDrag($ui, ngPin($ui, $ui->a), ngPin($ui, $ui->out));

    expect($asked)->toBe([[$ui->out, $ui->a]])->and($ui->g->linkCount())->toBe(0)->and($ui->loop->posted)->toBe([]);
    // the call that is not the user's is not asked
    expect($ui->g->connect($ui->out, $ui->a))->toBeGreaterThan(0);
});

it('replaces the one link of an input: NodeUnlinked for the old, NodeLinked for the new (node_graph.c:1846-1851)', function () {
    $ui = nodeGraphLive();
    $other = $ui->g->addOutput($ui->g->addNode('Other', 40, 260), 'Out', 'color');
    $ui->tree->layout(new Silk\Contracts\Geometry\Size(600, 400));
    $first = $ui->g->connect($ui->out, $ui->a);
    ngDrag($ui, ngPin($ui, $other), ngPin($ui, $ui->a));

    expect(ngMail($ui))->toBe(['NodeUnlinked', 'NodeLinked', 'NodeGraphEdited'])
        ->and([$ui->loop->posted[0]->link, $ui->loop->posted[0]->output])->toBe([$first, $ui->out])
        ->and($ui->g->linkCount())->toBe(1)->and($ui->g->linkOutput($ui->g->inputLink($ui->a)))->toBe($other);
});

it('keeps every link of an input that takes several, and pulling from it starts a new wire (node_graph.c:1841-1845, 1911-1917)', function () {
    $ui = nodeGraphLive();
    $ui->g->setPinMultiple($ui->a);
    $other = $ui->g->addOutput($ui->g->addNode('Other', 40, 260), 'Out', 'color');
    $ui->tree->layout(new Silk\Contracts\Geometry\Size(600, 400));
    $ui->g->connect($ui->out, $ui->a);
    ngDrag($ui, ngPin($ui, $other), ngPin($ui, $ui->a));
    $ui->loop->posted = [];
    // pulling from it, and letting go in the air, takes nothing away
    ngDrag($ui, ngPin($ui, $ui->a), ngAt($ui, 250, 330));

    expect($ui->g->linkCount())->toBe(2)->and($ui->loop->posted)->toBe([]);
});

it('picks up the end of a linked input: let go in the air the link goes (NodeUnlinked); plugged where it was it stays (node_graph.c:1963-1983)', function () {
    $ui = nodeGraphLive();
    $ui->g->connect($ui->out, $ui->a);
    ngDrag($ui, ngPin($ui, $ui->a), ngPin($ui, $ui->a), [ngAt($ui, 250, 120)]);
    expect($ui->g->linkCount())->toBe(1)->and($ui->loop->posted)->toBe([]);

    ngDrag($ui, ngPin($ui, $ui->a), ngAt($ui, 250, 330), [ngAt($ui, 250, 200)]);
    expect($ui->g->linkCount())->toBe(0)->and(ngMail($ui))->toBe(['NodeUnlinked', 'NodeGraphEdited']);
});

it('moves the picked-up wire to another input of the node (node_graph.c:1975-1990)', function () {
    $ui = nodeGraphLive();
    $ui->g->connect($ui->out, $ui->a);
    ngDrag($ui, ngPin($ui, $ui->a), ngPin($ui, $ui->b));

    expect($ui->g->inputLink($ui->a))->toBe(0)->and($ui->g->linkOutput($ui->g->inputLink($ui->b)))->toBe($ui->out)
        ->and(ngMail($ui))->toBe(['NodeUnlinked', 'NodeLinked', 'NodeGraphEdited']);
});

it('selects a node with a click, adds with Ctrl, forgets on the empty canvas, and tells the application which (node_graph.c:2157-2186)', function () {
    $ui = nodeGraphLive();
    [$x, $y] = $ui->g->nodePosition($ui->colour);
    $on = fn ($id) => ngAt($ui, ...[$ui->g->nodePosition($id)[0] + 60, $ui->g->nodePosition($id)[1] + 8]);

    click($ui->router, ...$on($ui->colour));
    expect($ui->g->isNodeSelected($ui->colour))->toBeTrue()->and(ngMail($ui))->toBe(['NodeSelectionChanged'])->and($ui->loop->posted[0]->node)->toBe($ui->colour);

    ngDrag($ui, $on($ui->mix), $on($ui->mix), [], MouseButton::LEFT, new Modifiers(ctrl: true));
    expect($ui->g->selectionCount())->toBe(2);

    click($ui->router, ...ngAt($ui, 200, 330));
    expect($ui->g->selectionCount())->toBe(0);
});

it('lifts a node over the others when it is pressed (node_graph.c:438-452)', function () {
    $ui = nodeGraphLive();
    $ui->g->setNodePosition($ui->mix, 100, 60);
    $ui->tree->layout(new Silk\Contracts\Geometry\Size(600, 400));
    // where they overlap the one drawn last is on top: Mix, added after
    click($ui->router, ...ngAt($ui, 150, 70));
    expect($ui->g->isNodeSelected($ui->mix))->toBeTrue();
    // pressing the part of Colour that is clear lifts it
    click($ui->router, ...ngAt($ui, 60, 70));
    expect($ui->g->isNodeSelected($ui->colour))->toBeTrue();
    click($ui->router, ...ngAt($ui, 150, 70));
    expect($ui->g->isNodeSelected($ui->colour))->toBeTrue()->and($ui->g->isNodeSelected($ui->mix))->toBeFalse();
});

it('moves the selected nodes by the drag, NodeMoved for each when the button goes up; a click that does not move posts none (node_graph.c:2027-2034, 1962-1971)', function () {
    $ui = nodeGraphLive();
    $ui->g->selectNode($ui->colour);
    $ui->g->selectNode($ui->mix, true);
    [$x, $y] = $ui->g->nodePosition($ui->colour);
    [$mx, $my] = $ui->g->nodePosition($ui->mix);
    ngDrag($ui, ngAt($ui, $x + 60, $y + 8), ngAt($ui, $x + 90, $y + 38), [ngAt($ui, $x + 75, $y + 20)]);

    expect($ui->g->nodePosition($ui->colour))->toBe([$x + 30.0, $y + 30.0])
        ->and($ui->g->nodePosition($ui->mix))->toBe([$mx + 30.0, $my + 30.0])
        ->and(ngMail($ui))->toBe(['NodeMoved', 'NodeMoved', 'NodeGraphEdited']);
    $ui->loop->posted = [];
    // a click on one of several selected nodes, without moving: it becomes the only one
    [$x, $y] = $ui->g->nodePosition($ui->colour);
    click($ui->router, ...ngAt($ui, $x + 60, $y + 8));
    expect($ui->g->selectionCount())->toBe(1)->and(ngMail($ui))->toBe(['NodeSelectionChanged']);
});

it('puts everything back with Escape while a move runs, and posts nothing (Review Focus 4; node_graph.c:2174-2178)', function () {
    $ui = nodeGraphLive();
    [$x, $y] = $ui->g->nodePosition($ui->colour);
    ngMouse($ui, EventType::MouseDown, ngAt($ui, $x + 60, $y + 8));
    ngMouse($ui, EventType::MouseMove, ngAt($ui, $x + 160, $y + 108));
    expect($ui->g->nodePosition($ui->colour))->not->toBe([$x, $y]);
    $ui->loop->posted = [];
    $ui->router->dispatch(new SilkEvent(EventType::KeyDown, key: Key::ESCAPE));
    ngMouse($ui, EventType::MouseUp, ngAt($ui, $x + 160, $y + 108));

    expect($ui->g->nodePosition($ui->colour))->toBe([$x, $y])->and($ui->loop->posted)->toBe([]);
});

it('puts a wire back with Escape: the link that was picked up stays (node_graph.c:2172-2178)', function () {
    $ui = nodeGraphLive();
    $ui->g->connect($ui->out, $ui->a);
    ngMouse($ui, EventType::MouseDown, ngPin($ui, $ui->a));
    ngMouse($ui, EventType::MouseMove, ngAt($ui, 250, 330));
    $ui->router->dispatch(new SilkEvent(EventType::KeyDown, key: Key::ESCAPE));
    ngMouse($ui, EventType::MouseUp, ngAt($ui, 250, 330));

    expect($ui->g->linkCount())->toBe(1)->and($ui->loop->posted)->toBe([]);
});

it('selects with a rectangle on the empty canvas; Ctrl adds to what was; Escape gives back what was (node_graph.c:2236-2248, 2190-2196)', function () {
    $ui = nodeGraphLive();
    ngDrag($ui, ngAt($ui, 10, 30), ngAt($ui, 200, 160), [ngAt($ui, 100, 100)]);
    expect($ui->g->isNodeSelected($ui->colour))->toBeTrue()->and($ui->g->isNodeSelected($ui->mix))->toBeFalse()
        ->and(ngMail($ui))->toBe(['NodeSelectionChanged']);

    ngDrag($ui, ngAt($ui, 300, 70), ngAt($ui, 520, 200), [ngAt($ui, 400, 100)], MouseButton::LEFT, new Modifiers(ctrl: true));
    expect($ui->g->selectionCount())->toBe(2);

    ngMouse($ui, EventType::MouseDown, ngAt($ui, 10, 300));
    ngMouse($ui, EventType::MouseMove, ngAt($ui, 590, 380));
    expect($ui->g->selectionCount())->toBe(0);
    $ui->router->dispatch(new SilkEvent(EventType::KeyDown, key: Key::ESCAPE));
    ngMouse($ui, EventType::MouseUp, ngAt($ui, 590, 380));
    expect($ui->g->selectionCount())->toBe(2);
});

it('selects a link by its wire, and Delete takes it away: NodeUnlinked (node_graph.c:2128-2137, 1868-1880)', function () {
    $ui = nodeGraphLive();
    $ui->g->connect($ui->out, $ui->a);
    nodeGraphPaint($ui);
    [$ax, $ay] = $ui->g->pinPosition($ui->out);
    [$bx, $by] = $ui->g->pinPosition($ui->a);
    $curve = Silk\Widgets\Nodes\Wire::curve($ax, $ay, $bx, $by, 0, 0);
    [$mx, $my] = Silk\Widgets\Nodes\Wire::point($curve, 0.5);
    $ui->focus->focus($ui->g);
    click($ui->router, ...ngAt($ui, $mx, $my));
    expect(ngMail($ui))->toBe(['NodeSelectionChanged']);
    $ui->loop->posted = [];
    ngKey($ui, Key::DELETE);

    expect($ui->g->linkCount())->toBe(0)->and(ngMail($ui))->toBe(['NodeUnlinked', 'NodeSelectionChanged', 'NodeGraphEdited']);
});

it('deletes selected nodes only when the application lets it: NodeRemoved, then NodeUnlinked for each link (node_graph.c:1882-1905)', function () {
    $ui = nodeGraphLive();
    $ui->g->connect($ui->out, $ui->a);
    $ui->focus->focus($ui->g);
    $ui->g->selectNode($ui->mix);
    ngKey($ui, Key::DELETE);
    expect($ui->g->nodeCount())->toBe(2);

    $ui->g->setRemovable()->setNodeDeletable($ui->colour, false);
    $ui->g->selectNode($ui->colour);
    $ui->g->selectNode($ui->mix, true);
    ngKey($ui, Key::BACKSPACE);

    expect($ui->g->nodeCount())->toBe(1)->and($ui->g->nodeTitle($ui->g->nodeAt(0)))->toBe('Colour')
        ->and(array_slice(ngMail($ui), 0, 2))->toBe(['NodeRemoved', 'NodeUnlinked'])
        ->and($ui->loop->posted[0]->title)->toBe('Mix');
});

it('leaves a key typed in a widget of a node alone, and is deaf when read only (node_graph.c:2179, 2186)', function () {
    $ui = nodeGraphLive();
    $ui->g->setRemovable()->selectNode($ui->colour);
    ngKey($ui, Key::DELETE);
    expect($ui->g->nodeCount())->toBe(2);

    $ui->focus->focus($ui->g);
    $ui->g->setReadOnly();
    ngKey($ui, Key::DELETE);
    ngDrag($ui, ngPin($ui, $ui->out), ngPin($ui, $ui->a));
    expect($ui->g->nodeCount())->toBe(2)->and($ui->g->linkCount())->toBe(0);
});

it('nudges the selection with the arrows, 8 or 40 with Shift, NodeMoved for each and one NodeGraphEdited; Ctrl+A selects all (node_graph.c:2193-2232)', function () {
    $ui = nodeGraphLive();
    $ui->focus->focus($ui->g);
    ngKey($ui, Key::A, new Modifiers(ctrl: true));
    expect($ui->g->selectionCount())->toBe(2)->and(ngMail($ui))->toBe(['NodeSelectionChanged']);
    $ui->loop->posted = [];
    [$x, $y] = $ui->g->nodePosition($ui->colour);
    ngKey($ui, Key::RIGHT);
    ngKey($ui, Key::UP, new Modifiers(shift: true));

    expect($ui->g->nodePosition($ui->colour))->toBe([$x + 8.0, $y - 40.0])
        ->and(ngMail($ui))->toBe(['NodeMoved', 'NodeMoved', 'NodeGraphEdited', 'NodeMoved', 'NodeMoved', 'NodeGraphEdited']);
});

it('opens a node on a double click (NodeActivated) and frames everything on a double click of the empty canvas (node_graph.c:2098-2101, 2138-2141)', function () {
    $ui = nodeGraphLive();
    [$x, $y] = $ui->g->nodePosition($ui->mix);
    $ui->router->dispatch(new SilkEvent(EventType::MouseDown, ...[...ngAt($ui, $x + 60, $y + 8)], button: MouseButton::LEFT, clicks: 2));
    $ui->router->dispatch(new SilkEvent(EventType::MouseUp, ...[...ngAt($ui, $x + 60, $y + 8)], button: MouseButton::LEFT, clicks: 2));
    expect(ngMail($ui))->toBe(['NodeActivated'])->and($ui->loop->posted[0]->node)->toBe($ui->mix);

    $before = $ui->g->view();
    ngMouse($ui, EventType::MouseDown, ngAt($ui, 100, 330), MouseButton::LEFT, 2);
    ngMouse($ui, EventType::MouseUp, ngAt($ui, 100, 330), MouseButton::LEFT, 2);
    for ($i = 0; $i < 200 && $ui->tree->advance(1 / 60); $i++) {
        nodeGraphPaint($ui);
    }
    expect($ui->g->view())->not->toBe($before);
});

it('asks for a menu on a right click: the node under it (selected first), or 0; the x and y are the widget\'s (node_graph.c:2076-2089)', function () {
    $ui = nodeGraphLive();
    [$x, $y] = $ui->g->nodePosition($ui->mix);
    ngMouse($ui, EventType::MouseDown, ngAt($ui, $x + 60, $y + 8), MouseButton::RIGHT);
    $m = $ui->loop->posted;

    expect(array_map(fn ($c) => $c::class, $m))->toBe([Silk\Contracts\Mail\NodeSelectionChanged::class, Silk\Contracts\Mail\NodeContextRequested::class])
        ->and($m[1]->node)->toBe($ui->mix);
    ngMouse($ui, EventType::MouseUp, ngAt($ui, $x + 60, $y + 8), MouseButton::RIGHT);
    $ui->loop->posted = [];
    ngMouse($ui, EventType::MouseDown, ngAt($ui, 100, 330), MouseButton::RIGHT);
    expect($ui->loop->posted[0]->node)->toBe(0);
});

it('zooms a stop a notch about the pointer, the point under it staying; trackpad and Ctrl zoom smoothly (node_graph.c:2125-2135, 598-608)', function () {
    $ui = nodeGraphLive();
    $at = ngAt($ui, 300, 200);
    $b = $ui->g->bounds();
    $ui->router->dispatch(new SilkEvent(EventType::MouseMove, x: $at[0], y: $at[1]));
    $ui->router->dispatch(new SilkEvent(EventType::Wheel, x: $at[0], y: $at[1], wheelY: 1.0));
    expect($ui->g->view()[2])->toBe(1.25);
    for ($i = 0; $i < 200 && $ui->tree->advance(1 / 60); $i++) {
        nodeGraphPaint($ui);
    }
    [$cx, $cy] = $ui->g->toCanvas($at[0] - $b->x, $at[1] - $b->y);
    expect(round($cx, 3))->toBe(300.0)->and(round($cy, 3))->toBe(200.0);

    $ui->router->dispatch(new SilkEvent(EventType::Wheel, x: $at[0], y: $at[1], wheelY: -2.0));
    expect($ui->g->view()[2])->toBe(0.8);
    $ui->router->dispatch(new SilkEvent(EventType::Wheel, x: $at[0], y: $at[1], wheelY: 0.5));
    $ui->router->dispatch(new SilkEvent(EventType::Wheel, x: $at[0], y: $at[1], wheelY: 0.5));
    expect($ui->g->view()[2])->toBe(1.0);
});

it('glides to the view it was given: the zoom is what the mouse works with, not the one asked (node_graph.c:7-14, 542-566)', function () {
    $ui = nodeGraphLive();
    $ui->router->dispatch(new SilkEvent(EventType::Wheel, x: 300, y: 200, wheelY: -1.0));
    $ui->tree->advance(1 / 60);
    $ui->tree->layout(new Silk\Contracts\Geometry\Size(600, 400));
    $mid = $ui->g->toCanvas(100, 100);

    expect($ui->g->view()[2])->toBe(0.8)->and($mid)->not->toBe($ui->g->toCanvas(100, 100) === [100.0, 100.0] ? [0.0, 0.0] : [100.0, 100.0]);
    for ($i = 0; $i < 200 && $ui->tree->advance(1 / 60); $i++) {
        $ui->tree->layout(new Silk\Contracts\Geometry\Size(600, 400));
    }
    $ui->g->render(new Venusian\Silk\Tests\Support\SpyPainter);
    expect(round($ui->g->fromCanvas(100, 0)[0] - $ui->g->fromCanvas(0, 0)[0], 3))->toBe(80.0);
});

it('pans with the middle button, or Alt, or Space and the left button, the canvas following the hand (node_graph.c:2063-2074, 2253-2264)', function () {
    $ui = nodeGraphLive();
    $first = $ui->g->toCanvas(100, 100);
    ngDrag($ui, ngAt($ui, 300, 330), ngAt($ui, 340, 350), [ngAt($ui, 320, 340)], MouseButton::MIDDLE);
    $second = $ui->g->toCanvas(100, 100);
    expect([round($second[0] - $first[0], 3), round($second[1] - $first[1], 3)])->toBe([-40.0, -20.0]);

    $ui->focus->focus($ui->g);
    $ui->router->dispatch(new SilkEvent(EventType::KeyDown, key: Key::SPACE));
    ngDrag($ui, ngAt($ui, 300, 330), ngAt($ui, 310, 330), [ngAt($ui, 305, 330)]);
    $ui->router->dispatch(new SilkEvent(EventType::KeyUp, key: Key::SPACE));
    $third = $ui->g->toCanvas(100, 100);
    expect(round($third[0] - $second[0], 3))->toBe(-10.0);

    ngDrag($ui, ngAt($ui, 300, 330), ngAt($ui, 300, 340), [ngAt($ui, 300, 335)], MouseButton::LEFT, new Modifiers(alt: true));
    expect(round($ui->g->toCanvas(100, 100)[1] - $third[1], 3))->toBe(-10.0)
        ->and($ui->loop->posted)->toBe([]);
});

it('slides on after the hand let go while it moved, by the speed it had (node_graph.c:2206-2211)', function () {
    $ui = nodeGraphLive();
    $t = 0.0;
    $ui->g->setClock(function () use (&$t) {
        return $t;
    });
    // (window points, fixed: the canvas moves under the hand)
    $b = $ui->g->bounds();
    $at = fn (float $x) => [$b->x + $x, $b->y + 330];
    ngMouse($ui, EventType::MouseDown, $at(300), MouseButton::MIDDLE);
    foreach ([1, 2, 3] as $i) {
        $t += 0.016;
        ngMouse($ui, EventType::MouseMove, $at(300 - 10 * $i));
    }
    $before = $ui->g->view()[0];
    $t += 0.02;
    ngMouse($ui, EventType::MouseUp, $at(270), MouseButton::MIDDLE);

    // the last 10 units took 0.016 s: 625 a second, x 0.14 = 87.5 further, to the right
    expect(round($ui->g->view()[0] - $before, 1))->toBe(87.5);
});

it('pans a trackpad\'s two fingers at once, and Shift or a sideways wheel slides the canvas (node_graph.c:2108-2124, 2695-2702)', function () {
    $ui = nodeGraphLive();
    $x0 = $ui->g->view()[0];
    $ui->router->dispatch(new SilkEvent(EventType::Wheel, x: 300, y: 200, wheelX: 1.0, wheelY: 0.0, precise: true));
    expect($ui->g->view()[0])->toBe($x0 - 48.0);

    $ui->router->dispatch(new SilkEvent(EventType::Wheel, x: 300, y: 200, wheelY: 1.0, modifiers: new Modifiers(shift: true)));
    expect($ui->g->view()[0])->toBe($x0 - 48.0 - 60.0);
});

it('hands a press to the widget in a node when it is large enough and its node is on top; below 0.45 the node takes it (node_graph.c:1025-1034)', function () {
    $ui = nodeGraphLive();
    $slider = new Silk\Widgets\Slider('level', 0, 100, 0);
    $ui->g->setNodeContent($ui->mix, $slider);
    $ui->tree->layout(new Silk\Contracts\Geometry\Size(600, 400));
    nodeGraphPaint($ui);
    $b = $slider->bounds();
    [$cx, $cy] = [$b->x + $b->w * 0.8, $b->y + $b->h / 2];
    // the widget's layout is the layer's: where it shows is the graph's transform of it
    [$s, $tx, $ty] = $ui->tree->transformOf($slider);
    click($ui->router, $cx * $s + $tx, $cy * $s + $ty);

    expect($slider->value())->toBeGreaterThan(50.0);

    $ui->g->setView(290, 190, 0.3);
    $ui->tree->layout(new Silk\Contracts\Geometry\Size(600, 400));
    $v = $slider->value();
    [$s, $tx, $ty] = $ui->tree->transformOf($slider);
    click($ui->router, ($b->x + $b->w * 0.2) * $s + $tx, ($b->y + $b->h / 2) * $s + $ty);
    expect($slider->value())->toBe($v);
});

it('shows a widget in a node at the zoom, laid out in the layer', function () {
    $ui = nodeGraphLive();
    $spacer = new Silk\Core\Layout\Spacer('preview', 100, 40);
    $ui->g->setNodeContent($ui->mix, $spacer);
    $ui->g->setView(290, 190, 2.0);
    $ui->tree->layout(new Silk\Contracts\Geometry\Size(600, 400));
    [$nx, $ny] = $ui->g->nodePosition($ui->mix);
    $r = $ui->g->nodeSize($ui->mix);
    [$s, $tx, $ty] = $ui->tree->transformOf($spacer);
    $b = $spacer->bounds();
    [$wx, $wy] = $ui->g->fromCanvas($nx + 10, $ny + 30 + 7 + 48 + 6);
    $g = $ui->g->bounds();

    expect($s)->toBe(2.0)->and(round($b->x * $s + $tx, 3))->toBe(round($g->x + $wx, 3))->and(round($b->y * $s + $ty, 3))->toBe(round($g->y + $wy, 3));
});

it('holds 1,000 nodes and 2,000 links in a bounded memory, and paints only what shows', function () {
    $g = new NodeGraph('big');
    $ui = silkUi(fn ($root) => $root->add($g, new Silk\Contracts\Widget\Slot(grow: 1)));
    $before = memory_get_usage();
    $outs = $ins = [];
    for ($i = 0; $i < 1000; $i++) {
        $n = $g->addNode("Node {$i}", ($i % 40) * 200, intdiv($i, 40) * 140);
        $outs[] = $g->addOutput($n, 'Out', 'number');
        $ins[] = $g->addInput($n, 'A', 'number');
        $ins[] = $g->addInput($n, 'B', 'number');
    }
    for ($i = 0; $i < 1000; $i++) {
        $g->connect($outs[$i], $ins[($i * 2 + 2) % 2000]);
        $g->connect($outs[$i], $ins[($i * 2 + 3) % 2000]);
    }
    $ui->tree->layout(new Silk\Contracts\Geometry\Size(600, 400));
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $g->render($p);

    expect($g->nodeCount())->toBe(1000)->and($g->linkCount())->toBeGreaterThan(1900)
        ->and(memory_get_usage() - $before)->toBeLessThan(48 * 1024 * 1024)
        ->and(count($p->calls))->toBeLessThan(40000);
    $g->clear();
    expect($g->linkCount())->toBe(0)->and($g->nodeCount())->toBe(0);
});
