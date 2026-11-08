<?php

use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Widgets\Toast;
use Silk\Widgets\ToastKind;
use Silk\Widgets\ToastQueue;
use Surface\Contracts\Windows\Mail\View\ButtonClicked;
use Venusian\Silk\Tests\Support\SpyPainter;

it('rises 14 units as it shows, SNAPPY, and goes after its seconds of loop time, hover or not', function () {
    $ui = toastUi();
    $ui->toast->show('Saved');
    $ui->tree->layout(new Size(300, 300));
    expect($ui->toast->isShown())->toBeTrue()->and($ui->toast->text())->toBe('Saved');
    toastRun($ui, 1.0);
    $ui->router->dispatch(new SilkEvent(EventType::MouseMove, x: centre($ui->toast)[0], y: centre($ui->toast)[1]));
    toastRun($ui, 3.1);
    expect($ui->toast->isShown())->toBeFalse();
});

it('replaces the message shown in the Replace mode, and waits its turn in the Sequence mode', function () {
    $ui = toastUi();
    $ui->toast->show('One')->show('Two');
    expect($ui->toast->count())->toBe(1)->and($ui->toast->text())->toBe('Two');
    $ui->toast->setQueue(ToastQueue::Sequence)->show('Three');
    expect($ui->toast->count())->toBe(2)->and($ui->toast->text())->toBe('Two');
    toastRun($ui, 4.1);
    expect($ui->toast->text())->toBe('Three');
});

it('stacks up to its maximum in the Stack mode, the oldest at the top, 8 between', function () {
    $ui = toastUi();
    $ui->toast->setQueue(ToastQueue::Stack)->setMaxVisible(2)->show('A')->show('B')->show('C');
    $ui->tree->layout(new Size(300, 300));
    $row = $ui->look->metric(Metric::ControlHeight) + 14;

    expect($ui->toast->count())->toBe(3)->and($ui->toast->bounds()->h)->toBe(2 * $row + Toast::GAP)->and($ui->toast->text())->toBe('B');
});

it('lets the pointer through when nothing is shown', function () {
    $ui = toastUi();
    $ui->tree->layout(new Size(300, 300));
    [$x, $y] = centre($ui->toast);
    expect($ui->tree->hitTest($x, $y))->not->toBe($ui->toast);
    $ui->toast->show('Here');
    $ui->tree->layout(new Size(300, 300));
    [$x, $y] = centre($ui->toast);
    expect($ui->tree->hitTest($x, $y))->toBe($ui->toast);
});

it('posts ButtonClicked from its action on a release over it, dismisses the message, and tells which', function () {
    $ui = toastUi();
    $ui->toast->setAction('Undo')->show('Deleted', 0.0, ToastKind::Danger);
    $ui->tree->layout(new Size(300, 300));
    $b = $ui->toast->bounds();
    $at = [$b->x + $b->w - 20, $b->y + $b->h / 2];
    click($ui->router, ...$at);
    $clicked = array_values(array_filter($ui->loop->posted, fn ($m) => $m instanceof ButtonClicked));

    expect($clicked)->toHaveCount(1)->and($clicked[0]->path)->toBe($ui->toast->path())
        ->and($ui->toast->text())->toBe('Deleted')->and($ui->toast->isShown())->toBeFalse();
});

it('shows the kind as a dot', function (ToastKind $kind, Role $role) {
    $ui = toastUi();
    $ui->toast->show('Kind', null, $kind);
    $ui->tree->layout(new Size(300, 300));
    settleAll($ui);
    $p = new SpyPainter;
    $ui->toast->render($p);
    $dots = array_filter($p->calls, fn ($c) => $c[0] === 'rect' && $c[1][0]->w === 8.0 && $c[1][2] === 4.0);

    expect(array_values($dots)[0][1][1])->toEqual($ui->look->color($role));
})->with([
    [ToastKind::Info, Role::Accent],
    [ToastKind::Success, Role::Success],
    [ToastKind::Warning, Role::Warning],
    [ToastKind::Danger, Role::Danger],
]);

it('measures its widest message between 160 and 480', function () {
    $ui = toastUi();
    $ui->toast->show(str_repeat('wide ', 200));
    $ui->tree->layout(new Size(1000, 300));
    expect($ui->toast->measure(new Size(1000, 300))->w)->toBe(Toast::MAX_WIDTH);
    $ui->toast->show('x');
    expect($ui->toast->measure(new Size(1000, 300))->w)->toBe(160.0);
});
