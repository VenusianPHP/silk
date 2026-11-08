<?php

use Silk\Contracts\Geometry\Size;
use Silk\Widgets\Label;
use Silk\Widgets\NodeGraph;

it('names nodes, pins and links by integers all different, and lists nodes in the order they were made (node_graph.c:2777-2827)', function () {
    $ui = nodeGraphUi();
    $g = $ui->g;
    $link = $g->connect($ui->out, $ui->a);

    expect([$ui->colour, $ui->out, $ui->mix, $ui->a, $ui->b, $ui->result, $link])->toBe([1, 2, 3, 4, 5, 6, 7])
        ->and([$g->nodeCount(), $g->nodeAt(0), $g->nodeAt(1), $g->nodeAt(2)])->toBe([2, 1, 3, 0])
        ->and([$g->inputAt($ui->mix, 1), $g->outputAt($ui->mix, 0), $g->inputAt($ui->mix, 2)])->toBe([$ui->b, $ui->result, 0])
        ->and([$g->pinNode($ui->b), $g->isPinOutput($ui->b), $g->pinLabel($ui->b), $g->pinType($ui->b)])->toBe([$ui->mix, false, 'B', 'color'])
        ->and($ui->loop->posted)->toBe([]);
});

it('links an output to an input of its type or of none, never two inputs, the same node or other types (node_graph.c:325-329, 3131-3140)', function () {
    $ui = nodeGraphUi();
    $g = $ui->g;
    $num = $g->addInput($ui->mix, 'Amount', 'number');
    $any = $g->addInput($ui->mix, 'Any');

    expect($g->connect($ui->a, $ui->b))->toBe(0)
        ->and($g->connect($ui->result, $ui->a))->toBe(0)
        ->and($g->connect($ui->out, $num))->toBe(0)
        ->and($g->connect($ui->a, $ui->out))->toBe(0)
        ->and($g->connect($ui->out, $any))->toBeGreaterThan(0)
        ->and($g->linkCount())->toBe(1);
});

it('gives an input one link (a new one replaces it) unless it takes several; the same pair is the same link (node_graph.c:359-380)', function () {
    $ui = nodeGraphUi();
    $g = $ui->g;
    $other = $g->addOutput($g->addNode('Other', 0, 300), 'Out', 'color');
    $first = $g->connect($ui->out, $ui->a);

    expect($g->connect($ui->out, $ui->a))->toBe($first);
    $second = $g->connect($other, $ui->a);
    expect([$g->linkCount(), $g->inputLink($ui->a), $g->linkOutput($second), $g->linkInput($second)])->toBe([1, $second, $other, $ui->a]);

    $g->setPinMultiple($ui->b);
    $g->connect($ui->out, $ui->b);
    $g->connect($other, $ui->b);
    expect($g->linkCount())->toBe(3);
});

it('takes a node away with its pins, their links and its widget (node_graph.c:402-430)', function () {
    $ui = nodeGraphUi();
    $g = $ui->g;
    $g->connect($ui->out, $ui->a);
    $g->setNodeContent($ui->mix, $label = new Label('swatch', 'mixed'));

    expect($label->parent())->toBe($g);
    $g->removeNode($ui->mix);

    expect([$g->nodeCount(), $g->linkCount(), $label->parent(), $g->children()])->toBe([1, 0, null, []])
        ->and(fn () => $g->pinNode($ui->a))->toThrow(InvalidArgumentException::class);
});

it('sizes a node from its title, pins and content: 148 wide at least, a row a pin under the header (node_graph.c:455-504)', function () {
    $ui = nodeGraphUi();
    $g = $ui->g;
    [$w, $h] = $g->nodeSize($ui->mix);
    [$x, $y] = $g->nodePosition($ui->mix);
    // Raven's header is 30 high, rows 24
    expect($w)->toBe(148.0)
        ->and($g->pinPosition($ui->a))->toBe([$x, $y + 30 + 7 + 12])
        ->and($g->pinPosition($ui->b))->toBe([$x, $y + 30 + 7 + 36])
        ->and($g->pinPosition($ui->result))->toBe([$x + $w, $y + 30 + 7 + 12])
        ->and($h)->toBe(ceil(30 + 7 + 48 + 5.0));

    $g->setNodeContent($ui->mix, new Silk\Core\Layout\Spacer('preview', 200, 40));
    [$w, $h] = $g->nodeSize($ui->mix);
    expect([$w, $h])->toBe([220.0, ceil(30 + 7 + 48 + 6 + 40 + 10.0)]);
});

it('converts between the widget and the canvas: widget = pan + zoom * canvas, the corner pinned until the view moves (node_graph.c:7-14, 516-608)', function () {
    $ui = nodeGraphUi();
    $g = $ui->g;

    expect($g->toCanvas(100, 50))->toBe([100.0, 50.0])
        ->and($g->view())->toBe([290.0, 190.0, 1.0]);

    $g->setView(200, 100, 2.0);
    [$cx, $cy] = $g->toCanvas(290, 190);
    expect([$cx, $cy])->toBe([200.0, 100.0])
        ->and($g->fromCanvas(210, 100))->toBe([310.0, 190.0])
        ->and($g->view())->toBe([200.0, 100.0, 2.0]);
});

it('holds the zoom between 0.2 and 2 and refuses one that is not above 0', function () {
    $ui = nodeGraphUi();
    $ui->g->setView(0, 0, 9);
    expect($ui->g->view()[2])->toBe(2.0);
    $ui->g->setView(0, 0, 0);
})->throws(InvalidArgumentException::class);

it('frames every node: the middle of their box, a round zoom no larger than life, 48 around (node_graph.c:610-640)', function () {
    $ui = nodeGraphUi();
    $g = $ui->g;
    $g->addNode('Far', 1400, 900);
    $g->frameAll();
    [$fw, $fh] = $g->nodeSize($g->findNode('Far'));
    [$x1, $y1] = [1400 + $fw, 900 + $fh];
    $zoom = floor(min(580 / ($x1 - 40 + 96), 380 / ($y1 - 60 + 96)) * 20) / 20;

    expect($g->view())->toBe([(40 + $x1) / 2, (60 + $y1) / 2, $zoom]);
});

it('frames once it has a size when asked before', function () {
    $g = new NodeGraph('g');
    $g->addNode('A', 500, 500);
    $g->frameAll();
    $ui = silkUi(fn ($root) => $root->add($g, new Silk\Contracts\Widget\Slot(grow: 1)));
    $ui->tree->layout(new Size(600, 400));

    expect($g->view()[0])->toBeGreaterThan(500.0);
});

it('finds a node by name or else title, a pin by name or else label, without case (node_graph.c:2343-2372)', function () {
    $ui = nodeGraphUi();
    $g = $ui->g;
    $g->setNodeName($ui->colour, 'source');
    $g->setPinName($ui->a, 'first');

    expect([$g->findNode('SOURCE'), $g->findNode('colour'), $g->findNode('mix'), $g->findNode('nope')])->toBe([$ui->colour, 0, $ui->mix, 0])
        ->and([$g->findPin($ui->mix, 'First'), $g->findPin($ui->mix, 'a'), $g->findPin($ui->mix, 'b')])->toBe([$ui->a, 0, $ui->b]);
});

it('colours a type as it was given, else from its name; a pin of no type is grey (node_graph.c:313-323)', function () {
    $ui = nodeGraphUi();
    $g = $ui->g;
    $picked = NodeGraph::PALETTE[hexdec(hash('fnv1a32', 'color')) % 10];
    $any = $g->addInput($ui->mix, 'Any');

    expect($g->pinColor($ui->a)->toHex())->toBe(strtolower($picked));
    $g->setTypeColor('color', Surface\NutsAndBolts\Color::hex('#123456'));
    expect($g->pinColor($ui->a)->toHex())->toBe('#123456')
        ->and($g->pinColor($any)->alpha)->toBe(1.0);
});

it('selects nodes alone or added, and posts nothing for it', function () {
    $ui = nodeGraphUi();
    $g = $ui->g;
    $g->selectNode($ui->colour);
    $g->selectNode($ui->mix, true);

    expect([$g->selectionCount(), $g->selectedNode(0), $g->selectedNode(1), $g->isNodeSelected($ui->colour)])->toBe([2, $ui->colour, $ui->mix, true]);
    $g->selectNode($ui->mix);
    expect($g->selectionCount())->toBe(1);
    $g->clearSelection();
    expect([$g->selectionCount(), $ui->loop->posted])->toBe([0, []]);
});
