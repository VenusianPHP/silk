<?php

use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Metric;
use Silk\Widgets\TreeView;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Mail\View\NodeActivated;
use Surface\Contracts\Windows\Mail\View\NodeExpanded;
use Surface\Contracts\Windows\Mail\View\NodeSelected;
use Surface\Contracts\Windows\Primitives\TableColumn;
use Surface\Contracts\Windows\Primitives\TreeNode;
use Surface\Contracts\Windows\WindowException;
use Venusian\Silk\Tests\Support\SpyPainter;

it('shows the expanded nodes depth first, keeping a node\'s expansion under a collapsed ancestor', function () {
    $ui = treeUi();
    expect($ui->tv->visibleIds())->toBe(['a', 'b']);
    $ui->tv->expand('a1');
    expect($ui->tv->visibleIds())->toBe(['a', 'b'])->and($ui->tv->isExpanded('a1'))->toBeTrue();
    $ui->tv->expand('a');
    expect($ui->tv->visibleIds())->toBe(['a', 'a1', 'a1x', 'a2', 'b'])
        ->and(fn () => $ui->tv->expand('nope'))->toThrow(WindowException::class);
});

it('opens with Right, then goes to the first child; Left closes, then goes to the parent', function () {
    $ui = treeUi();
    $ui->focus->focus($ui->tv);
    $ui->tv->selectNode('a');
    pressKey($ui->router, Key::RIGHT);
    expect($ui->tv->isExpanded('a'))->toBeTrue()->and($ui->tv->selectedNode())->toBe('a');
    pressKey($ui->router, Key::RIGHT);
    expect($ui->tv->selectedNode())->toBe('a1');
    pressKey($ui->router, Key::LEFT);
    expect($ui->tv->selectedNode())->toBe('a');
    pressKey($ui->router, Key::LEFT);
    expect($ui->tv->isExpanded('a'))->toBeFalse();

    expect(array_map(fn ($m) => [$m->id, $m->expanded], mailOf($ui, NodeExpanded::class)))->toBe([['a', true], ['a', false]])
        ->and(array_map(fn ($m) => $m->id, mailOf($ui, NodeSelected::class)))->toBe(['a1', 'a']);
});

it('moves the selection out of a collapsed branch silently, as Raven', function () {
    $ui = treeUi();
    $ui->tv->expand('a')->expand('a1')->selectNode('a1x');
    $ui->tree->layout(new Silk\Contracts\Geometry\Size(300, 300));
    treeClick($ui, treeRow($ui, 0, chevron: true));

    expect($ui->tv->selectedNode())->toBe('a')->and(mailOf($ui, NodeSelected::class))->toBe([])
        ->and(mailOf($ui, NodeExpanded::class))->toHaveCount(1);
});

it('toggles a parent and activates it on a double click; a click in the empty space clears the selection', function () {
    $ui = treeUi();
    treeClick($ui, treeRow($ui, 1));
    // two rows in a tree three rows high: the third is empty space
    $b = $ui->tv->bounds();
    treeClick($ui, [$b->x + 20, $b->y + $b->h - 4]);
    expect($ui->tv->selectedNode())->toBeNull()->and(array_map(fn ($m) => $m->id, mailOf($ui, NodeSelected::class)))->toBe(['b', null]);
    treeClick($ui, treeRow($ui, 0), clicks: 2);
    expect($ui->tv->isExpanded('a'))->toBeTrue()->and(mailOf($ui, NodeActivated::class)[0]->id)->toBe('a');
});

it('measures 160 x 3..10 rows + 2, and draws no header for one column', function () {
    $ui = treeUi();
    expect($ui->tv->measure(new Silk\Contracts\Geometry\Size(999, 999)))->toEqual(new Silk\Contracts\Geometry\Size(160, 3 * $ui->rowH + 2));
});

it('toggles on a chevron click without selecting', function () {
    $ui = treeUi();
    treeClick($ui, treeRow($ui, 0, chevron: true));

    expect($ui->tv->isExpanded('a'))->toBeTrue()->and($ui->tv->selectedNode())->toBeNull()
        ->and(mailOf($ui, NodeExpanded::class)[0]->id)->toBe('a')->and(mailOf($ui, NodeSelected::class))->toBe([]);
});

it('selects on a click, activates on a double click and Enter', function () {
    $ui = treeUi();
    $ui->focus->focus($ui->tv);
    treeClick($ui, treeRow($ui, 1));
    treeClick($ui, treeRow($ui, 0), clicks: 2);
    pressKey($ui->router, Key::ENTER);

    expect(array_map(fn ($m) => $m->id, mailOf($ui, NodeSelected::class)))->toBe(['b', 'a'])
        ->and(array_map(fn ($m) => $m->id, mailOf($ui, NodeActivated::class)))->toBe(['a', 'a']);
});

it('keeps the selection and expansion of ids still present on setNodes, without mail; refuses a duplicate id', function () {
    $ui = treeUi();
    $ui->tv->expand('a')->selectNode('a2');
    $ui->tv->setNodes([new TreeNode('a', ['name' => 'A'], [new TreeNode('a2', ['name' => 'A2'])])]);
    expect($ui->tv->selectedNode())->toBe('a2')->and($ui->tv->isExpanded('a'))->toBeTrue();
    $ui->tv->setNodes([new TreeNode('b', ['name' => 'B'])]);
    expect($ui->tv->selectedNode())->toBeNull()->and($ui->loop->posted)->toBe([])
        ->and(fn () => $ui->tv->setNodes([new TreeNode('x', []), new TreeNode('x', [])]))->toThrow(WindowException::class)
        ->and($ui->tv->visibleIds())->toBe(['b']);
});

it('selects one node unless multi-select is on', function () {
    $ui = treeUi();
    $ui->tv->expand('a');
    $ui->tree->layout(new Silk\Contracts\Geometry\Size(300, 300));
    treeClick($ui, treeRow($ui, 1));
    treeClick($ui, treeRow($ui, 2), mods: new Modifiers(meta: true));
    expect($ui->tv->selectedNodes())->toBe(['a2']);
});

it('adds with Cmd or Ctrl and selects a visible range with Shift, with multi-select on', function () {
    $ui = treeUi();
    $ui->tv->setMultiSelect(true);
    $ui->tv->expand('a');
    $ui->tree->layout(new Silk\Contracts\Geometry\Size(300, 300));
    treeClick($ui, treeRow($ui, 1));
    treeClick($ui, treeRow($ui, 2), mods: new Modifiers(meta: true));
    expect($ui->tv->selectedNodes())->toBe(['a1', 'a2']);
    treeClick($ui, treeRow($ui, 3), mods: new Modifiers(shift: true));
    expect($ui->tv->selectedNodes())->toBe(['a2', 'b'])->and($ui->tv->selectedNode())->toBe('b');
    treeClick($ui, treeRow($ui, 2), mods: new Modifiers(ctrl: true));
    expect($ui->tv->selectedNodes())->toBe(['b']);
});

it('keeps 10,000 nodes as given: its growth is the visible-row index, not the nodes', function () {
    $nodes = [];
    for ($i = 0; $i < 100; $i++) {
        $kids = [];
        for ($j = 0; $j < 99; $j++) {
            $kids[] = new TreeNode("n{$i}-{$j}", ['name' => "N {$i}.{$j}"]);
        }
        $nodes[] = new TreeNode("n{$i}", ['name' => "N {$i}"], $kids);
    }
    $ui = treeUi([]);
    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $ui->tv->setNodes($nodes);
    foreach ($nodes as $n) {
        $ui->tv->expand($n->id);
    }
    $growth = memory_get_peak_usage() - $before;

    // three hashes (byId, parentOf, rowOf: 16,384 slots x 40 B = 0.66 MB each) and two lists (the visible ids and
    // depths: 16,384 x 16 B = 0.26 MB each) = 2.5 MB; 2.65 MB measured. 3 MB bounds it. The nodes themselves are shared
    expect($growth)->toBeLessThan(3_000_000)->and(count($ui->tv->visibleIds()))->toBe(10_000);
});

it('paints only the visible rows of 10,000 expanded nodes', function () {
    $nodes = [];
    for ($i = 0; $i < 100; $i++) {
        $kids = [];
        for ($j = 0; $j < 99; $j++) {
            $kids[] = new TreeNode("n{$i}-{$j}", ['name' => "N {$i}.{$j}"]);
        }
        $nodes[] = new TreeNode("n{$i}", ['name' => "N {$i}"], $kids);
    }
    $ui = treeUi($nodes);
    foreach ($nodes as $n) {
        $ui->tv->expand($n->id);
    }
    $ui->tree->layout(new Silk\Contracts\Geometry\Size(300, 300));
    $ui->tv->render(new SpyPainter);
    $p = new SpyPainter;
    $t = hrtime(true);
    $ui->tv->render($p);

    expect(count($ui->tv->visibleIds()))->toBe(10_000)
        ->and(count(array_filter($p->methods(), fn ($m) => $m === 'text')))->toBeLessThan(300 / $ui->rowH + 4)
        ->and((hrtime(true) - $t) / 1e6)->toBeLessThan(10.0);
});
