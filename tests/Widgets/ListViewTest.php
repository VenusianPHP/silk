<?php

use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Mail\RowActivated;
use Silk\Widgets\ListView;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Mail\View\SelectionChanged;
use Venusian\Silk\Tests\Support\SpyPainter;

it('paints only the visible rows of 100,000, fast', function () {
    $ui = listUi(100_000);
    $ui->list->render(new SpyPainter);
    $p = new SpyPainter;
    $t = hrtime(true);
    $ui->list->render($p);
    $ms = (hrtime(true) - $t) / 1e6;

    $texts = count(array_filter($p->methods(), fn ($m) => $m === 'text'));
    expect($texts)->toBeLessThanOrEqual((int) ($ui->list->scroll()->viewport / $ui->rowH) + 3)->toBeGreaterThan(0)->and($ms)->toBeLessThan(5.0);
});

it('asks a virtual list only for the rows it draws', function () {
    $ui = listUi(1);
    $asked = [];
    $ui->list->setVirtualItems(1_000_000, function (int $i) use (&$asked) {
        $asked[] = $i;

        return "V{$i}";
    });
    $ui->tree->layout(new Size(300, 300));
    $ui->list->render(new SpyPainter);

    expect(count($asked))->toBeLessThan(20)->and($ui->list->rowCount())->toBe(1_000_000)->and($ui->list->text(5))->toBe('V5');
});

it('takes 100,000 items in under 50 ms', function () {
    $ui = listUi(1);
    $items = [];
    for ($i = 1; $i <= 100_000; $i++) {
        $items[] = "Row {$i}";
    }
    $t = hrtime(true);
    $ui->list->setItems($items);
    $ui->tree->layout(new Size(300, 300));

    expect((hrtime(true) - $t) / 1e6)->toBeLessThan(50.0)->and($ui->list->items())->toHaveCount(100_000);
});

it('measures 160 wide and 3 to 8 rows high, plus 2', function () {
    $ui = listUi(1);
    expect($ui->list->measure(new Size(999, 999)))->toEqual(new Size(160, 3 * $ui->rowH + 2));
    $ui->list->setItems(array_fill(0, 20, 'x'));
    expect($ui->list->measure(new Size(999, 999)))->toEqual(new Size(160, 8 * $ui->rowH + 2));
});

it('selects a clicked row and posts SelectionChanged once', function () {
    $ui = listUi();
    click($ui->router, ...rowPoint($ui, 2));

    expect($ui->list->selected())->toBe(2)
        ->and($ui->loop->posted)->toHaveCount(1)
        ->and($ui->loop->posted[0])->toBeInstanceOf(SelectionChanged::class)
        ->and($ui->loop->posted[0]->index)->toBe(2)
        ->and($ui->loop->posted[0]->option)->toBe('Item 3');
});

it('goes to the last row on Up with none selected; stays on the last row at Down', function () {
    $ui = listUi(5);
    $ui->focus->focus($ui->list);
    pressKey($ui->router, Key::UP);
    expect($ui->list->selected())->toBe(4)->and($ui->loop->posted)->toHaveCount(1);
    pressKey($ui->router, Key::DOWN);
    expect($ui->list->selected())->toBe(4)->and($ui->loop->posted)->toHaveCount(1);
});

it('moves a page (the rows in view less one) at PageDown and reveals the row', function () {
    $ui = listUi();
    $ui->focus->focus($ui->list);
    $ui->list->select(0);
    pressKey($ui->router, Key::PAGE_DOWN);
    $page = max(1, (int) ($ui->list->scroll()->viewport / $ui->rowH) - 1);
    pressKey($ui->router, Key::PAGE_DOWN);

    expect($ui->list->selected())->toBe(2 * $page)
        ->and($ui->list->scroll()->offset)->toBe((2 * $page + 1) * $ui->rowH - $ui->list->scroll()->viewport);
});

it('reveals a row selected by the app, posting nothing', function () {
    $ui = listUi();
    $ui->list->select(60);

    expect($ui->list->scroll()->offset)->toBeGreaterThan(0.0)->and($ui->loop->posted)->toBe([]);
});

it('scrolls 3 rows a wheel notch and lets the wheel through at an end', function () {
    $ui = listUi();
    [$x, $y] = rowPoint($ui, 1);
    expect($ui->router->dispatch(new SilkEvent(EventType::Wheel, x: $x, y: $y, wheelY: 1)))->toBeTrue()
        ->and($ui->list->scroll()->offset)->toBe(3 * $ui->rowH)
        ->and($ui->list->handle(new SilkEvent(EventType::Wheel, x: $x, y: $y, wheelY: -5)))->toBeTrue()
        ->and($ui->list->scroll()->offset)->toBe(0.0)
        ->and($ui->list->handle(new SilkEvent(EventType::Wheel, x: $x, y: $y, wheelY: -1)))->toBeFalse();
});

it('clears a selection past new items without mail', function () {
    $ui = listUi();
    $ui->list->select(50);
    $ui->list->setItems(['a', 'b']);

    expect($ui->list->selected())->toBeNull()->and($ui->loop->posted)->toBe([]);
});

it('posts RowActivated on Enter and on a double click', function () {
    $ui = listUi();
    $ui->focus->focus($ui->list);
    $ui->list->select(3);
    pressKey($ui->router, Key::ENTER);
    [$x, $y] = rowPoint($ui, 1);
    $ui->router->dispatch(new SilkEvent(EventType::MouseDown, x: $x, y: $y, button: MouseButton::LEFT, clicks: 2));
    $ui->router->dispatch(new SilkEvent(EventType::MouseUp, x: $x, y: $y, button: MouseButton::LEFT, clicks: 2));

    $activated = array_values(array_filter($ui->loop->posted, fn ($m) => $m instanceof RowActivated));
    expect($activated)->toHaveCount(2)->and($activated[0]->row)->toBe(3)->and($activated[1]->row)->toBe(1);
});

it('slides the selection and shows it dimmer without the focus', function () {
    $ui = listUi();
    $ui->list->select(0);
    $ui->list->render(new SpyPainter);
    expect($ui->list->highlightY())->toBe(0.0);
    $ui->list->select(4);
    $ui->list->render(new SpyPainter);
    $ui->tree->advance(1 / 60);
    expect($ui->list->highlightY())->toBeLessThan(4 * $ui->rowH);
    settleAll($ui);
    $ui->list->render(new SpyPainter);
    settleAll($ui);
    expect($ui->list->highlightY())->toEqualWithDelta(4 * $ui->rowH, 0.01);

    $p = new SpyPainter;
    $ui->list->render($p);
    $sel = $ui->look->color(Role::Selection);
    $in = $ui->look->color(Role::Input);
    $fills = array_map(fn ($c) => $c[1][1], array_filter($p->calls, fn ($c) => $c[0] === 'rect'));
    expect($fills)->toContainEqual(new Surface\NutsAndBolts\Color(
        $sel->red + ($in->red - $sel->red) * 0.45, $sel->green + ($in->green - $sel->green) * 0.45,
        $sel->blue + ($in->blue - $sel->blue) * 0.45, $sel->alpha + ($in->alpha - $sel->alpha) * 0.45));
});

it('takes no input when disabled', function () {
    $ui = listUi();
    $ui->list->setEnabled(false);
    click($ui->router, ...rowPoint($ui, 2));

    expect($ui->list->selected())->toBeNull()->and($ui->loop->posted)->toBe([]);
});

it('keeps 100,000 items without copying them', function () {
    $ui = listUi(1);
    $items = [];
    for ($i = 1; $i <= 100_000; $i++) {
        $items[] = "Row {$i}";
    }
    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $ui->list->setItems($items);
    $ui->tree->layout(new Size(300, 300));
    $ui->list->render(new SpyPainter);
    $growth = memory_get_peak_usage() - $before;

    // the items are the caller's array, shared: a copy of it would add 1.6 MB (16 B a slot; the strings are shared
    // anyway). What grows is one frame drawn and measured, ~0.5 MB measured; 1 MB bounds it below one copy
    expect($growth)->toBeLessThan(1_000_000);
});
