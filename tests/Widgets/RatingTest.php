<?php

use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Role;
use Surface\Contracts\HumanInput\Key;
use Venusian\Silk\Tests\Support\SpyPainter;

it('measures the stars 4 apart, 18 by default, + 4', function () {
    $ui = ratingUi();

    expect($ui->rating->bounds()->w)->toBe(18 * 5 + 4 * 4 + 4.0);
});

it('gives the star clicked, and takes the score given back to 0 on a second click', function () {
    $ui = ratingUi();
    $cy = centre($ui->rating)[1];
    click($ui->router, $ui->rating->starX(3), $cy);
    click($ui->router, $ui->rating->starX(3), $cy);

    expect(ratingMail($ui))->toBe([4.0, 0.0])->and($ui->rating->value())->toBe(0.0);
});

it('gives halves on the left half of a star with halves allowed', function () {
    $ui = ratingUi();
    $ui->rating->setAllowHalf(true);
    click($ui->router, $ui->rating->starX(2) - 4, centre($ui->rating)[1]);

    expect($ui->rating->value())->toBe(2.5);
});

it('shows the score the pointer would give, without changing it, in a colour mixed toward Accent', function () {
    $ui = ratingUi(1.0);
    $ui->router->dispatch(new SilkEvent(EventType::MouseMove, x: $ui->rating->starX(4), y: centre($ui->rating)[1]));
    $p = new SpyPainter;
    $ui->rating->render($p);
    $filled = array_filter($p->calls, fn ($c) => $c[0] === 'triangle');
    $on = $ui->look->color(Role::Warning);

    expect($ui->rating->value())->toBe(1.0)->and($ui->rating->hoverValue())->toBe(5.0)
        ->and(count($filled))->toBe(50)->and(array_values($filled)[0][1][6])->not->toEqual($on);
});

it('steps with the keys and the wheel, Home and End going to 0 and the most', function () {
    $ui = ratingUi(2.0);
    $ui->focus->focus($ui->rating);
    pressKey($ui->router, Key::RIGHT);
    pressKey($ui->router, Key::DOWN);
    pressKey($ui->router, Key::END);
    pressKey($ui->router, Key::HOME);
    [$x, $y] = centre($ui->rating);
    $ui->router->dispatch(new SilkEvent(EventType::Wheel, x: $x, y: $y, wheelY: 2));

    expect(ratingMail($ui))->toBe([3.0, 2.0, 5.0, 0.0, 2.0]);
});

it('takes no input read-only, and posts nothing for setValue', function () {
    $ui = ratingUi(2.0);
    $ui->rating->setReadOnly(true)->setValue(4.0);
    click($ui->router, $ui->rating->starX(0), centre($ui->rating)[1]);

    expect($ui->rating->value())->toBe(4.0)->and($ui->loop->posted)->toBe([]);
});

it('draws a half star clipped to its half', function () {
    $ui = ratingUi();
    $ui->rating->setAllowHalf(true)->setValue(1.5);
    $p = new SpyPainter;
    $ui->rating->render($p);
    $clips = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'pushClip'));

    expect($clips)->toHaveCount(1)->and($clips[0][1][0]->w)->toBe(9.0);
});
