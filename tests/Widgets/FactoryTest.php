<?php

use Silk\Core\Layout;
use Silk\Widgets;
use Silk\Widgets\Column;

it('has every tier 1 creation method, returning its class', function (string $method, array $args, string $class) {
    $col = new Column('window');

    $w = $col->{$method}(...$args);

    expect($w)->toBeInstanceOf($class)->and($col->children())->toContain($w)->and($w->name())->toBe('w');
})->with([
    'label' => ['label', ['w', 'Hi'], Widgets\Label::class],
    'button' => ['button', ['w', 'OK'], Widgets\Button::class],
    'checkbox' => ['checkbox', ['w', 'Agree'], Widgets\Checkbox::class],
    'toggle' => ['toggle', ['w'], Widgets\Toggle::class],
    'progressBar' => ['progressBar', ['w', 0.5], Widgets\ProgressBar::class],
    'separator' => ['separator', ['w'], Widgets\Separator::class],
    'spacer' => ['spacer', ['w', 1, 1], Layout\Spacer::class],
    'column' => ['column', ['w'], Widgets\Column::class],
    'row' => ['row', ['w'], Widgets\Row::class],
    'textInput' => ['textInput', ['w'], Widgets\TextInput::class],
    'spinBox' => ['spinBox', ['w'], Widgets\SpinBox::class],
    'image' => ['image', ['w'], Widgets\Image::class],
    'sketch' => ['sketch', ['w', fn () => null], Widgets\Sketch::class],
    'grid' => ['grid', ['w'], Widgets\Grid::class],
    'uniformGrid' => ['uniformGrid', ['w'], Widgets\UniformGrid::class],
    'stack' => ['stack', ['w'], Widgets\Stack::class],
    'sizeBox' => ['sizeBox', ['w'], Widgets\SizeBox::class],
    'switcher' => ['switcher', ['w'], Widgets\Switcher::class],
    'wrapBox' => ['wrapBox', ['w'], Widgets\WrapBox::class],
    'fixed' => ['fixed', ['w'], Widgets\Fixed::class],
    'border' => ['border', ['w'], Widgets\Border::class],
    'dropdown' => ['dropdown', ['w', ['a', 'b']], Widgets\Dropdown::class],
    'comboBox' => ['comboBox', ['w', '', ['a']], Widgets\ComboBox::class],
    'slider' => ['slider', ['w', 0, 10, 5], Widgets\Slider::class],
    'scrollView' => ['scrollView', ['w'], Widgets\ScrollBox::class],
    'scrollBar' => ['scrollBar', ['w'], Widgets\ScrollBar::class],
    'split' => ['split', ['w'], Widgets\Splitter::class],
    'tabView' => ['tabView', ['w'], Widgets\TabView::class],
    'expander' => ['expander', ['w', 'More'], Widgets\Expander::class],
]);

it('gives every panel the creation methods, so panels nest like TK groups', function (string $method) {
    $panel = (new Column('window'))->{$method}('p');

    expect($panel->button('ok', 'OK'))->toBeInstanceOf(Widgets\Button::class)->and($panel->children())->toHaveCount(1);
})->with(['column', 'row', 'grid', 'uniformGrid', 'stack', 'sizeBox', 'switcher', 'wrapBox', 'fixed', 'border']);

it('gives dialogs, tab pages and expander bodies the creation methods', function () {
    $tabs = (new Column('window'))->tabView('tabs');

    expect($tabs->addPage('a', 'A')->button('ok', 'OK'))->toBeInstanceOf(Widgets\Button::class)
        ->and((new Widgets\Dialog('d'))->label('t', 'Title'))->toBeInstanceOf(Widgets\Label::class)
        ->and((new Widgets\Expander('x', 'X'))->body()->toggle('t'))->toBeInstanceOf(Widgets\Toggle::class);
});

it('merges both lanes\' styles into RavenDark', function () {
    $styles = array_map(fn ($r) => $r[0], Silk\Looks\RavenDark::theme()['styles']);

    expect($styles)->toContain('TextInput')->toContain('SpinBox')->toContain('Dropdown');
});

it('paints a Border with a background as a panel of glass in a Raven glass look, rounded by 20 unless told otherwise (border.c:117-126)', function () {
    $ui = silkUi(fn ($root) => $root->border('card', 8)->setBackground(Silk\Contracts\Look\Role::Panel)->setShadow(12, Silk\Contracts\Look\Role::Shadow));
    $ui->tree->reducedMotion = true;
    $ui->tree->setLook(Silk\Looks\BuiltInThemes::register(new Silk\Looks\LookManager, 'sans-serif', 'monospace')->use('raven-glass-dark')->current());
    $ui->tree->layout(new Silk\Contracts\Geometry\Size(300, 300));
    $card = $ui->root->children()[0];
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $card->paint($p);
    [$call] = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'glass'));

    expect($p->methods())->toBe(['glass'])
        ->and($call[1][1])->toBe([20.0, 20.0, 20.0, 20.0])
        ->and($call[1][2]->shadow)->toBe(12.0)
        ->and($call[1][2]->bevel)->toBe(12.0);

    $plain = silkUi(fn ($root) => $root->border('pad', 8));
    $plain->tree->setLook($ui->tree->look);
    $q = new Venusian\Silk\Tests\Support\SpyPainter;
    $plain->root->children()[0]->paint($q);
    expect($q->calls)->toBe([]);
});
