<?php

use Silk\Contracts\Geometry\Size;
use Silk\Looks\BuiltInThemes;
use Silk\Looks\LookManager;
use Venusian\Silk\Tests\Support\SpyPainter;

function glassInputIn(string $theme, \Closure $make): object
{
    $ui = silkUi($make);
    $ui->tree->reducedMotion = true;
    $ui->tree->setLook(BuiltInThemes::register(new LookManager, 'sans-serif', 'monospace')->use($theme)->current());
    $ui->tree->layout(new Size(300, 300));
    settleAll($ui);
    $ui->w = $ui->root->children()[0];
    $ui->spy = new SpyPainter;
    $ui->w->paint($ui->spy);

    return $ui;
}

function glassCalls(object $ui): array
{
    return array_values(array_filter($ui->spy->calls, fn ($c) => $c[0] === 'glass'));
}

dataset('glass input widgets', [
    'text input (text_input.c:66)' => [fn ($r) => $r->textInput('w', 'abc')],
    'spin box (spin_box.c:220)' => [fn ($r) => $r->spinBox('w', 5)],
    'dropdown (combo_box.c:371)' => [fn ($r) => $r->dropdown('w', ['a', 'b'])],
    'date picker (date_picker.c:512)' => [fn ($r) => $r->add(new Silk\Widgets\DatePicker('w'))],
    'colour picker (color_picker.c:604)' => [fn ($r) => $r->colorPicker('w')->setPopup(true)],
]);

it('draws its field as one glass surface, as round as it is tall, in a glass look', function (Closure $make) {
    $ui = glassInputIn('raven-glass-dark', $make);
    $calls = glassCalls($ui);

    expect($calls)->toHaveCount(1)->and($calls[0][1][1])->toBe($ui->w->bounds()->h / 2);
})->with('glass input widgets');

it('draws no glass in a look that has none', function (Closure $make) {
    expect(glassCalls(glassInputIn('raven-dark', $make)))->toBe([]);
})->with('glass input widgets');

it('rings a focused glass text input 1.5 wide in the focus colour', function () {
    $ui = silkUi(fn ($r) => $r->textInput('w', 'abc'));
    $ui->tree->reducedMotion = true;
    $ui->tree->setLook(BuiltInThemes::register(new LookManager, 'sans-serif', 'monospace')->use('raven-glass-dark')->current());
    $ui->tree->layout(new Size(300, 300));
    $ui->focus->focus($ui->root->children()[0]);
    settleAll($ui);
    $spy = new SpyPainter;
    $ui->root->children()[0]->paint($spy);
    $rings = array_filter($spy->calls, fn ($c) => $c[0] === 'rect' && ($c[1][4] ?? 0) === 1.5);

    expect($rings)->toHaveCount(1);
});

it('shows a glass bead behind a file browser navigation button only while the pointer is on it (file_browser.c:790)', function () {
    $ui = browserUi(sys_get_temp_dir());
    $ui->tree->reducedMotion = true;
    $ui->tree->setLook(BuiltInThemes::register(new LookManager, 'sans-serif', 'monospace')->use('raven-glass-dark')->current());
    $ui->tree->layout(new Size(900, 600));
    $paint = function () use ($ui) {
        $spy = new SpyPainter;
        $ui->fb->paint($spy);

        return count(array_filter($spy->calls, fn ($c) => $c[0] === 'glass'));
    };
    $idle = $paint();
    [$x, $y] = $ui->fb->navPoint('up');
    $ui->router->dispatch(new Silk\Contracts\Input\SilkEvent(Silk\Contracts\Input\EventType::MouseMove, x: $x, y: $y));
    $paint(); // the spring takes its target while it paints
    settleAll($ui);

    expect($paint())->toBe($idle + 1);
});

it('draws a text editor frame as input glass, radius at most 14, in a glass look (text_editor.c:968)', function () {
    $ui = glassInputIn('raven-glass-dark', fn ($r) => $r->add(new Silk\Widgets\TextEditor('w')));
    $calls = glassCalls($ui);

    expect($calls)->toHaveCount(1)->and($calls[0][1][1])->toBeLessThanOrEqual(14.0);
});
