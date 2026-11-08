<?php

use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Widgets\Breadcrumb;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;

it('lays its items out 22 apart, each its text + 12', function () {
    $ui = crumbUi(['Home', 'Docs', 'Report']);
    $m = $ui->tree->measurer();
    $font = $ui->look->font();
    [$a, $b] = [$ui->crumbs->itemRect(0), $ui->crumbs->itemRect(1)];

    expect($a->w)->toBe(ceil($m->measure('Home', $font)->w) + 12)->and($b->x)->toBe($a->x + $a->w + Breadcrumb::GAP);
});

it('folds the first items into "..." when they do not fit, which stands for the last folded one', function () {
    $ui = crumbUi(['Home', 'Users', 'Angel', 'Projects', 'Silk', 'Report'], 200);
    $first = $ui->crumbs->firstShown();
    expect($first)->toBeGreaterThan(0);
    click($ui->router, ...centre_of($ui->crumbs->itemRect(0)));

    expect(crumbMail($ui))->toBe([[$first - 1, ['Home', 'Users', 'Angel', 'Projects', 'Silk', 'Report'][$first - 1]]]);
});

it('posts on a release over the link pressed; the last item is no link', function () {
    $ui = crumbUi(['Home', 'Docs', 'Report']);
    click($ui->router, ...centre_of($ui->crumbs->itemRect(1)));
    click($ui->router, ...centre_of($ui->crumbs->itemRect(2)));
    drag3($ui, centre_of($ui->crumbs->itemRect(0)), centre_of($ui->crumbs->itemRect(1)));

    expect(crumbMail($ui))->toBe([[1, 'Docs']]);
});

it('starts the keyboard on the last link, moves with Left and Right, and follows with Enter', function () {
    $ui = crumbUi(['Home', 'Docs', 'Report']);
    $ui->focus->focus($ui->crumbs);
    pressKey($ui->router, Key::ENTER);
    pressKey($ui->router, Key::LEFT);
    pressKey($ui->router, Key::LEFT);
    pressKey($ui->router, Key::SPACE);

    expect(crumbMail($ui))->toBe([[1, 'Docs'], [0, 'Home']]);
});

it('truncates to a count', function () {
    $ui = crumbUi(['Home', 'Docs', 'Report']);
    $ui->crumbs->truncate(2);

    expect($ui->crumbs->items())->toBe(['Home', 'Docs']);
});
