<?php

use Silk\Contracts\Look\Role;
use Silk\Widgets\BackgroundBlur;
use Silk\Widgets\BlurStyle;
use Surface\NutsAndBolts\Color;
use Venusian\Silk\Tests\Support\SpyPainter;

/*
 * BackgroundBlur (background_blur.c): one child over a blurred view of what is behind it. The styles' numbers are Raven's.
 */

function blurUi(?callable $configure = null, string $look = 'raven-dark'): object
{
    $ui = silkUi(function ($root) use ($configure) {
        $b = $root->backgroundBlur('pane');
        $b->label('inside', 'Hello');
        $configure && $configure($b);
    });
    $ui->pane = $ui->root->children()[0];

    return $ui;
}

function blurCall(BackgroundBlur $pane): array
{
    $p = new SpyPainter;
    $pane->paint($p);
    $calls = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'blur'));

    return $calls[0][1] ?? [];
}

it('is made by the factory, with one child', function () {
    $ui = blurUi();

    expect($ui->pane)->toBeInstanceOf(BackgroundBlur::class)
        ->and($ui->pane->children())->toHaveCount(1)
        ->and($ui->pane->style())->toBe(BlurStyle::Frosted);
});

it('paints Frosted as Raven does: the veil at 0.52 of the panel alpha, saturation 1.35, grain 0.55, a light, a hairline', function () {
    $ui = blurUi();
    [$rect, $radii, $look] = blurCall($ui->pane);
    $panel = $ui->look->color(Role::Panel);

    expect($look->radius)->toBe($ui->look->metric(Silk\Contracts\Look\Metric::BlurRadius))
        ->and($look->tint->alpha)->toEqualWithDelta(min(1.0, $panel->alpha) * 0.52, 1e-9)
        ->and([$look->tint->red, $look->tint->green, $look->tint->blue])->toBe([$panel->red, $panel->green, $panel->blue])
        ->and([$look->saturation, $look->grain, $look->light, $look->borderWidth])->toBe([1.35, 0.55, 0.01, 1.0])
        ->and($look->border->alpha)->toBe(0.14)
        ->and($radii)->toBe(array_fill(0, 4, $ui->look->metric(Silk\Contracts\Look\Metric::CornerRadius)));
});

it('lights a frosted pane more and strengthens its hairline in a light look', function () {
    $ui = blurUi();
    inLook($ui, 'raven-light');
    [, , $look] = blurCall($ui->pane);

    expect($look->light)->toBe(0.03)->and($look->border->alpha)->toBe(0.55);
});

it('paints Plain with no veil, no grain and no hairline unless asked', function () {
    $ui = blurUi(fn ($b) => $b->setStyle(BlurStyle::Plain));
    [, , $look] = blurCall($ui->pane);

    expect($look->tint)->toBeNull()
        ->and([$look->saturation, $look->grain, $look->light, $look->borderWidth])->toBe([1.0, 0.0, 0.0, 0.0]);

    $tinted = blurUi(fn ($b) => $b->setStyle(BlurStyle::Plain)->setTint(Color::rgba(10, 20, 30, 0.3)));
    expect(blurCall($tinted->pane)[2]->tint->alpha)->toBe(0.3);
});

it('takes its own radius, saturation, grain, corner radii and shadow over the style\'s', function () {
    $ui = blurUi(fn ($b) => $b->setRadius(30)->setSaturation(2.0)->setGrain(0.1)->setCornerRadius(4, 8, 12, 16)->setShadow(24));
    [, $radii, $look] = blurCall($ui->pane);

    expect([$look->radius, $look->saturation, $look->grain])->toBe([30.0, 2.0, 0.1])
        ->and($radii)->toBe([4.0, 8.0, 12.0, 16.0])
        ->and($look->shadow)->toBe(24.0)
        ->and($look->shadowColor->toHex())->toBe($ui->look->color(Role::Shadow)->toHex());
});

it('paints Native as Frosted: a Silk window is never seen through (background_blur.c:100)', function () {
    $native = blurCall(blurUi(fn ($b) => $b->setStyle(BlurStyle::Native))->pane);
    $frosted = blurCall(blurUi()->pane);

    expect($native[2])->toEqual($frosted[2]);
});

it('uses the widget\'s tint as the veil whole, whatever the style', function () {
    $ui = blurUi(fn ($b) => $b->setTint(Color::rgba(200, 10, 10, 0.9)));

    expect(blurCall($ui->pane)[2]->tint->alpha)->toBe(0.9);
});

it('lays its child inside the padding', function () {
    $ui = blurUi(fn ($b) => $b->setPadding(10, 6, 14, 8));
    $pane = $ui->pane->bounds();
    $inside = $ui->pane->children()[0]->bounds();

    expect([$inside->x - $pane->x, $inside->y - $pane->y])->toBe([10.0, 6.0])
        ->and($pane->w - ($inside->x - $pane->x) - $inside->w)->toBeGreaterThanOrEqual(14.0 - 1e-6);
});

it('paints Glass as a panel of glass in the look, its frost following the radius (background_blur.c:84-98)', function () {
    $ui = blurUi(fn ($b) => $b->setStyle(BlurStyle::Glass)->setRadius(11)->setShadow(16));
    $p = new SpyPainter;
    $ui->pane->paint($p);
    $calls = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'glass'));
    [, , $glass] = $calls[0][1];

    expect(count($calls))->toBe(1)
        ->and($glass->frost)->toBe(0.5)
        ->and($glass->shadow)->toBe(16.0)
        ->and($glass->rim->alpha)->toBe(0.5)
        ->and($glass->tint->alpha)->toEqualWithDelta(min(1.0, $ui->look->color(Role::Panel)->alpha) * 0.4, 1 / 255);
});
