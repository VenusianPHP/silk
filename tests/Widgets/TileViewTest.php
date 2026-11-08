<?php

use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Mail\RowActivated;
use Silk\Widgets\TileItem;
use Silk\Widgets\TileMode;
use Silk\Widgets\TileView;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;
use Venusian\Silk\Tests\Support\SpyPainter;

it('lays Raven\'s 112 tiles with spacing 8 out, columns leaving room for the bar', function () {
    $ui = tileUi();
    $bar = $ui->look->metric(Metric::ScrollbarSize);
    $w = $ui->view->bounds()->w;
    expect($ui->view->columns())->toBe(max(1, (int) floor(($w - 8 - $bar) / 120)))
        ->and($ui->view->tileRect(0)->x - $ui->view->bounds()->x)->toBe(8.0)
        ->and($ui->view->tileRect(1)->x - $ui->view->tileRect(0)->x)->toBe(120.0)
        ->and($ui->view->tileRect(0)->w)->toBe(112.0);
    $ui->tree->layout(new Size(80, 300));
    expect($ui->view->columns())->toBe(1);
});

it('is one item a line in Rows mode, the view less two spacings and the bar wide', function () {
    $ui = tileUi();
    $ui->view->setMode(TileMode::Rows);
    $ui->tree->layout(new Size(300, 300));
    $bar = $ui->look->metric(Metric::ScrollbarSize);

    expect($ui->view->columns())->toBe(1)->and($ui->view->tileRect(0)->w)->toBe($ui->view->bounds()->w - 16 - $bar)
        ->and($ui->view->tileRect(1)->y - $ui->view->tileRect(0)->y)->toBe(120.0);
});

it('treats the gaps between tiles as empty space', function () {
    $ui = tileUi();
    $r = $ui->view->tileRect(0);

    expect($ui->view->tileAt($r->x + $r->w + 4, $r->y + 10))->toBe(-1)->and($ui->view->tileAt($r->x + 4, $r->y + 4))->toBe(0);
});

it('asks the app only for the tiles drawn', function () {
    $ui = tileUi(100_000);
    $ui->view->render(new SpyPainter);

    expect(count($ui->asked))->toBeLessThan(40);
});

it('selects with a band from the empty space; Ctrl or Shift add to what was selected; the current is the last inside', function () {
    $ui = tileUi(6);
    $r0 = $ui->view->tileRect(0);
    $r1 = $ui->view->tileRect(1);
    $gapX = $r0->x + $r0->w + 4;
    $ui->router->dispatch(new SilkEvent(EventType::MouseDown, x: $gapX, y: $r0->y + 10, button: MouseButton::LEFT, clicks: 1));
    $ui->router->dispatch(new SilkEvent(EventType::MouseMove, x: $r1->x + 10, y: $r1->y + 20, button: MouseButton::LEFT));
    $ui->router->dispatch(new SilkEvent(EventType::MouseUp, x: $r1->x + 10, y: $r1->y + 20, button: MouseButton::LEFT));
    expect($ui->view->selected())->toBe([1])->and($ui->view->current())->toBe(1);

    click($ui->router, ...tilePoint($ui, 0));
    $ui->router->dispatch(new SilkEvent(EventType::MouseDown, x: $gapX, y: $r0->y + 10, button: MouseButton::LEFT, clicks: 1, modifiers: new Modifiers(shift: true)));
    $ui->router->dispatch(new SilkEvent(EventType::MouseMove, x: $r1->x + 10, y: $r1->y + 20, button: MouseButton::LEFT));
    $ui->router->dispatch(new SilkEvent(EventType::MouseUp, x: $r1->x + 10, y: $r1->y + 20, button: MouseButton::LEFT));
    expect($ui->view->selected())->toBe([0, 1]);
});

it('toggles with Ctrl, takes a range with Shift, all with Ctrl+A', function () {
    $ui = tileUi(10);
    $ui->focus->focus($ui->view);
    click($ui->router, ...tilePoint($ui, 1));
    $at = tilePoint($ui, 3);
    $ui->router->dispatch(new SilkEvent(EventType::MouseDown, x: $at[0], y: $at[1], button: MouseButton::LEFT, clicks: 1, modifiers: new Modifiers(shift: true)));
    expect($ui->view->selected())->toBe([1, 2, 3]);
    $ui->router->dispatch(new SilkEvent(EventType::MouseUp, x: $at[0], y: $at[1], button: MouseButton::LEFT));
    $at = tilePoint($ui, 2);
    $ui->router->dispatch(new SilkEvent(EventType::MouseDown, x: $at[0], y: $at[1], button: MouseButton::LEFT, clicks: 1, modifiers: new Modifiers(ctrl: true)));
    expect($ui->view->selected())->toBe([1, 3]);
    $ui->router->dispatch(new SilkEvent(EventType::KeyDown, key: Key::A, modifiers: new Modifiers(ctrl: true)));
    expect($ui->view->selectionCount())->toBe(10);
});

it('keeps the others on a press on a selected tile, and only it on a release without a drag', function () {
    $ui = tileUi(10);
    click($ui->router, ...tilePoint($ui, 1));
    $at = tilePoint($ui, 2);
    $ui->router->dispatch(new SilkEvent(EventType::MouseDown, x: $at[0], y: $at[1], button: MouseButton::LEFT, clicks: 1, modifiers: new Modifiers(ctrl: true)));
    $ui->router->dispatch(new SilkEvent(EventType::MouseUp, x: $at[0], y: $at[1], button: MouseButton::LEFT));
    [$x, $y] = tilePoint($ui, 1);
    $ui->router->dispatch(new SilkEvent(EventType::MouseDown, x: $x, y: $y, button: MouseButton::LEFT, clicks: 1));
    expect($ui->view->selected())->toBe([1, 2])->and($ui->view->current())->toBe(1);
    $ui->router->dispatch(new SilkEvent(EventType::MouseUp, x: $x, y: $y, button: MouseButton::LEFT));
    expect($ui->view->selected())->toBe([1]);
});

it('moves by a tile and a line with the arrows, and activates the current on Enter and a double click', function () {
    $ui = tileUi(10);
    $ui->focus->focus($ui->view);
    $cols = $ui->view->columns();
    // from none, Right is the first tile (tile_view.c:498)
    pressKey($ui->router, Key::RIGHT);
    pressKey($ui->router, Key::DOWN);
    expect($ui->view->current())->toBe($cols);
    pressKey($ui->router, Key::ENTER);
    [$x, $y] = tilePoint($ui, $cols);
    $ui->router->dispatch(new SilkEvent(EventType::MouseDown, x: $x, y: $y, button: MouseButton::LEFT, clicks: 2));
    $act = array_values(array_filter($ui->loop->posted, fn ($m) => $m instanceof RowActivated));

    expect(array_map(fn ($m) => $m->row, $act))->toBe([$cols, $cols]);
});

it('scrolls 0.75 of a line a notch, and leaves the wheel to the app with Ctrl', function () {
    $ui = tileUi(200);
    [$x, $y] = tilePoint($ui, 0);
    expect($ui->view->handle(new SilkEvent(EventType::Wheel, x: $x, y: $y, wheelY: 1)))->toBeTrue()
        ->and($ui->view->scroll()->offset)->toBe(90.0)
        ->and($ui->view->handle(new SilkEvent(EventType::Wheel, x: $x, y: $y, wheelY: 1, modifiers: new Modifiers(ctrl: true))))->toBeFalse();
});

it('drags the selection past 5 units with a drag type, labelled with its count', function () {
    $ui = tileUi(10);
    $ui->view->setDragType('tiles');
    click($ui->router, ...tilePoint($ui, 1));
    $at = tilePoint($ui, 2);
    $ui->router->dispatch(new SilkEvent(EventType::MouseDown, x: $at[0], y: $at[1], button: MouseButton::LEFT, clicks: 1, modifiers: new Modifiers(ctrl: true)));
    $ui->router->dispatch(new SilkEvent(EventType::MouseMove, x: $at[0] + 6, y: $at[1], button: MouseButton::LEFT));
    $d = $ui->tree->drag();

    expect($d->payload->kind)->toBe('tiles')->and($d->payload->label)->toBe('T2 (+1)')->and($d->payload->data)->toBe('1 2');
});

it('keeps the selection of 100,000 tiles as one byte a tile', function () {
    $ui = tileUi(1);
    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $ui->view->setItems(100_000, fn (int $i) => new TileItem("T{$i}"));
    $ui->tree->layout(new Size(300, 300));
    $ui->focus->focus($ui->view);
    $ui->router->dispatch(new SilkEvent(EventType::KeyDown, key: Key::A, modifiers: new Modifiers(ctrl: true)));
    $ui->view->render(new SpyPainter);
    $growth = memory_get_peak_usage() - $before;

    // the selection is a 100,000-byte string (0.1 MB); the selected() list Ctrl+A posts is the peak, 100,000 ints
    // (1.6 MB as a packed list, built once for the mail). 2.5 MB bounds both and one frame drawn
    expect($growth)->toBeLessThan(2_500_000)->and($ui->view->selectionCount())->toBe(100_000);
});
