<?php

use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Widgets\TreeView;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;
use Venusian\Silk\Tests\Support\SpyPainter;

it('starts no drag without a drag type', function () {
    $ui = expandedTreeUi();
    [$x, $y] = treeRow($ui, 3);
    $ui->router->dispatch(new SilkEvent(EventType::MouseDown, x: $x, y: $y, button: MouseButton::LEFT, clicks: 1));
    $ui->router->dispatch(new SilkEvent(EventType::MouseMove, x: $x + 40, y: $y, button: MouseButton::LEFT));

    expect($ui->tree->drag())->toBeNull();
});

it('drags the selection once the press moves more than 5 units on either axis, labelled with its count', function () {
    $ui = expandedTreeUi();
    $ui->tv->setMultiSelect(true)->setDragType('nodes');
    treeClick($ui, treeRow($ui, 2));
    treeClick($ui, treeRow($ui, 3), mods: new Modifiers(meta: true));
    [$x, $y] = treeRow($ui, 3);
    $ui->router->dispatch(new SilkEvent(EventType::MouseDown, x: $x, y: $y, button: MouseButton::LEFT, clicks: 1));
    $ui->router->dispatch(new SilkEvent(EventType::MouseMove, x: $x + TreeView::DRAG_START, y: $y, button: MouseButton::LEFT));
    expect($ui->tree->drag())->toBeNull()->and($ui->tv->selectedNodes())->toBe(['a2', 'b']);
    $ui->router->dispatch(new SilkEvent(EventType::MouseMove, x: $x, y: $y + TreeView::DRAG_START + 1, button: MouseButton::LEFT));

    $d = $ui->tree->drag();
    expect($d)->not->toBeNull()->and($d->payload->kind)->toBe('nodes')->and($d->payload->label)->toBe('B (+1)')
        ->and($d->payload->data)->toBe('a2 b');
});

it('keeps the others on a press on one of several, and keeps it alone on a release without a drag', function () {
    $ui = expandedTreeUi();
    $ui->tv->setMultiSelect(true);
    treeClick($ui, treeRow($ui, 2));
    treeClick($ui, treeRow($ui, 3), mods: new Modifiers(meta: true));
    [$x, $y] = treeRow($ui, 2);
    $ui->router->dispatch(new SilkEvent(EventType::MouseDown, x: $x, y: $y, button: MouseButton::LEFT, clicks: 1));
    expect($ui->tv->selectedNodes())->toBe(['a2', 'b']);
    $ui->router->dispatch(new SilkEvent(EventType::MouseUp, x: $x, y: $y, button: MouseButton::LEFT, clicks: 1));
    expect($ui->tv->selectedNodes())->toBe(['a2']);
});

it('lights the drop node the app names', function () {
    $ui = expandedTreeUi();
    $ui->tv->setDropHighlight('a1');
    $p = new SpyPainter;
    $ui->tv->render($p);
    $accent = $ui->look->color(Silk\Contracts\Look\Role::Accent);
    $framed = array_filter($p->calls, fn ($c) => $c[0] === 'rect' && ($c[1][3] ?? null) == $accent && $c[1][4] === 1.5);

    expect($framed)->toHaveCount(1);
});
