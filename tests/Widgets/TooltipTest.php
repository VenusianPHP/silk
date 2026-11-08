<?php

use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Core\Overlay\Tooltip;
use Surface\Contracts\HumanInput\MouseButton;

function hoverAt(object $ui, float $x, float $y): void
{
    $ui->router->dispatch(new SilkEvent(EventType::MouseMove, x: $x, y: $y));
}

function wait(object $ui, float $seconds): void
{
    for ($t = 0.0; $t < $seconds - 1e-9; $t += 1 / 60) {
        $ui->tree->advance(1 / 60);
    }
    $ui->tree->layout(new Size(300, 300));
}

function tipUi(): object
{
    $ui = silkUi(fn ($root) => $root->button('ok', 'OK'));
    $ui->ok = $ui->root->children()[0]->setTooltip('Saves the file');

    return $ui;
}

it('shows the tooltip after 0.6 s, not at 0.5 s', function () {
    $ui = tipUi();
    hoverAt($ui, ...centre($ui->ok));

    wait($ui, 0.5);
    expect($ui->tree->tooltip())->toBeNull();

    wait($ui, 0.12);
    expect($ui->tree->tooltip())->toBeInstanceOf(Tooltip::class)
        ->and($ui->tree->tooltip()->text())->toBe('Saves the file')
        ->and($ui->tree->overlays()->top())->toBeNull(); // passive: not the top popup, never hit
});

it('re-arms after the pointer moves 7 units, not 5', function () {
    $ui = tipUi();
    [$x, $y] = centre($ui->ok);
    hoverAt($ui, $x, $y);
    wait($ui, 0.4);
    hoverAt($ui, $x + 5, $y);
    wait($ui, 0.22);
    expect($ui->tree->tooltip())->not->toBeNull();

    $ui = tipUi();
    hoverAt($ui, $x, $y);
    wait($ui, 0.4);
    hoverAt($ui, $x + 7, $y);
    wait($ui, 0.22);
    expect($ui->tree->tooltip())->toBeNull();
    wait($ui, 0.4);
    expect($ui->tree->tooltip())->not->toBeNull();
});

it('sits at pointer +12, +20 and flips above near the bottom edge', function () {
    $ui = tipUi();
    [$x, $y] = centre($ui->ok);
    hoverAt($ui, $x, $y);
    wait($ui, 0.7);
    $b = $ui->tree->tooltip()->bounds();
    expect([$b->x, $b->y])->toBe([$x + 12, $y + 20]);

    $low = silkUi(function ($root) {
        $root->spacer('gap', 10, 250);
        $root->button('ok', 'OK');
    });
    $ok = $low->root->children()[1]->setTooltip('Low');
    [$x, $y] = centre($ok);
    hoverAt($low, $x, $y);
    wait($low, 0.7);
    $b = $low->tree->tooltip()->bounds();
    expect($b->y + $b->h)->toBeLessThanOrEqual($y - 6 + 1e-9);
});

it('hides on any mouse press', function () {
    $ui = tipUi();
    [$x, $y] = centre($ui->ok);
    hoverAt($ui, $x, $y);
    wait($ui, 0.7);

    $ui->router->dispatch(new SilkEvent(EventType::MouseDown, x: $x, y: $y, button: MouseButton::LEFT, clicks: 1));

    expect($ui->tree->tooltip()?->isOpen() ?? false)->toBeFalse();
});

it('stops asking for frames once the tooltip has settled (idle cost zero)', function () {
    $ui = tipUi();
    hoverAt($ui, ...centre($ui->ok));

    $last = true;
    for ($t = 0.0; $t < 3.0; $t += 1 / 60) {
        $last = $ui->tree->advance(1 / 60);
    }

    expect($ui->tree->tooltip())->not->toBeNull()->and($last)->toBeFalse();
});

it('keeps RavenDark identical', function () {
    $ui = tipIn();
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $ui->tip->render($p);

    expectPaintFixture('tooltip-raven-dark', array_map(fn ($c) => [$c[0], array_map(fn ($a) => $a instanceof Surface\NutsAndBolts\Color ? $a->toHex() : ($a instanceof Silk\Contracts\Geometry\Rect ? [$a->x, $a->y, $a->w, $a->h] : (is_object($a) ? $a::class : (is_float($a) ? round($a, 6) : $a))), $c[1])], $p->calls));
});

it('draws the platform tooltip by family: size, padding, box (window.c:130-186, platform_looks.c:240-259)', function (string $look, float $size, float $padX, float $padY, ?float $shadow, float $radius, bool $border) {
    $ui = tipIn($look);
    $tip = $ui->tip;
    $b = $tip->bounds();
    [$x, $y] = $ui->point;
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $tip->render($p);
    $calls = array_column($p->calls, 1, 0);
    $font = $calls['text'][4];
    $m = $ui->tree->measurer();
    $w = $m->measure('Saves the file', $font)->w;
    $lh = $m->metrics($font)['lineHeight'];

    expect($font->size)->toBe($size)
        ->and([$b->w, $b->h])->toBe([ceil($w) + 2 * $padX, ceil($lh) + 2 * $padY + 2])
        ->and($b->y)->toBe($y + 22.0)
        ->and($calls['text'][1])->toBe($b->x + $padX)
        ->and($calls['rect'][2])->toBe($radius)
        ->and(isset($calls['rect'][3]) && $calls['rect'][4] > 0)->toBe($border)
        ->and(isset($calls['shadow']) ? $calls['shadow'][2] : null)->toBe($shadow);
})->with([
    'macOS' => ['macos-light', 11.0, 6.0, 3.0, 6.0, 4.0, true],
    'GNOME' => ['gnome-dark', 13.0, 10.0, 6.0, null, 6.0, false],
]);

it('opens on a SMOOTH spring on a smooth look, BOUNCY on a springy one (window.c:139, 201)', function (string $look, Silk\Core\Motion\SpringPreset $preset) {
    expect(tipIn($look)->tip->motionSprings()[0]->preset)->toBe($preset);
})->with([
    ['raven-light', Silk\Core\Motion\SpringPreset::Bouncy],
    ['gnome-light', Silk\Core\Motion\SpringPreset::Smooth],
]);
