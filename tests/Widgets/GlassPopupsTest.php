<?php

use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Look\Metric;
use Silk\Core\Overlay\Tooltip;
use Silk\Looks\BuiltInThemes;
use Silk\Looks\LookManager;
use Silk\Widgets\Dialog;
use Silk\Widgets\Menu;
use Silk\Widgets\MenuBar;
use Venusian\Silk\Tests\Support\SpyPainter;

function glassUi(string $theme, \Closure $make): object
{
    $ui = silkUi($make);
    $ui->tree->reducedMotion = true;
    $ui->tree->setLook(BuiltInThemes::register(new LookManager, 'sans-serif', 'monospace')->use($theme)->current());
    $ui->tree->layout(new Size(300, 300));

    return $ui;
}

function popupGlassOf(SpyPainter $p): array
{
    return array_values(array_filter($p->calls, fn ($c) => $c[0] === 'glass'));
}

it('draws its popup surface as glass, with the look\'s popup radius (common.c:435)', function (string $theme) {
    $ui = glassUi($theme, fn ($root) => $root->button('ok', 'OK'));
    $menu = (new Menu('m'))->in($ui->tree);
    $menu->item('a', 'Alpha');
    $menu->openAt(20, 20);
    settleAll($ui);
    $p = new SpyPainter;
    $menu->render($p);
    $glass = popupGlassOf($p);

    expect($glass)->toHaveCount(1)->and($glass[0][1][1])->toBe($ui->tree->look->metric(Metric::PopupRadius))
        ->and(array_filter($p->calls, fn ($c) => $c[0] === 'shadow'))->toBe([]);
})->with(['raven-glass-dark', 'macos-glass-light']);

it('draws a menu bar as a strip of glass, its titles capsules 3 in from the top and bottom (menu.c:741-789)', function () {
    $ui = glassUi('raven-glass-dark', function ($root) {
        $bar = new MenuBar('bar');
        $bar->menu('file', 'File')->item('quit', 'Quit');
        $root->add($bar);
    });
    $bar = $ui->root->children()[0];
    $p = new SpyPainter;
    $bar->paint($p);
    $glass = popupGlassOf($p);

    expect($glass)->toHaveCount(1)->and($glass[0][1][1])->toBe(0.0);
});

it('raises the sheet of a dialog as 26-radius glass behind a glass veil (window.c:806-837)', function () {
    $ui = glassUi('raven-glass-dark', fn ($root) => $root->button('ok', 'OK'));
    $dialog = (new Dialog('confirm'))->in($ui->tree);
    $dialog->add(new Silk\Core\Layout\Spacer('body', 100, 60));
    $dialog->openAt(0, 0);
    $ui->tree->layout(new Size(300, 300));
    settleAll($ui);
    $p = new SpyPainter;
    $dialog->render($p);
    $glass = popupGlassOf($p);

    expect($glass)->toHaveCount(2)->and($glass[0][1][1])->toBe(0.0)->and($glass[1][1][1])->toBe(26.0);
});

it('shows a tooltip as a drop of glass: radius at most 12, frost 1, refraction 8, saturation 1.6, shadow 12 (window.c:216-228)', function () {
    $ui = glassUi('raven-glass-dark', fn ($root) => $root->button('ok', 'OK')->setTooltip('Saves the file'));
    $button = $ui->root->children()[0];
    $ui->router->dispatch(new Silk\Contracts\Input\SilkEvent(Silk\Contracts\Input\EventType::MouseMove, x: $button->bounds()->x + 3, y: $button->bounds()->y + 3));
    for ($i = 0; $i < 60; $i++) {
        $ui->tree->advance(1 / 60);
    }
    $ui->tree->layout(new Size(300, 300));
    $tip = $ui->tree->tooltip();
    $p = new SpyPainter;
    $tip->render($p);
    $glass = popupGlassOf($p);

    expect($tip)->toBeInstanceOf(Tooltip::class)->and($glass)->toHaveCount(1)->and($glass[0][1][1])->toBe(min($tip->bounds()->h / 2, 12.0))
        ->and([$glass[0][1][2]->frost, $glass[0][1][2]->refraction, $glass[0][1][2]->saturation, $glass[0][1][2]->shadow])->toBe([1.0, 8.0, 1.6, 12.0]);
});
