<?php

use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Widgets\ComboBox;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Mail\View\SelectionChanged;
use Surface\Contracts\Windows\Mail\View\TextSubmitted;

function comboUi(): object
{
    $ui = silkUi(fn ($root) => $root->add(new ComboBox('fruit', 'pear', ['apple', 'banana', 'cherry'])));
    $ui->combo = $ui->root->children()[0];

    return $ui;
}

function settleCombo(object $ui): void
{
    for ($i = 0; $i < 200 && $ui->tree->advance(1 / 60); $i++) {
    }
    $ui->tree->layout(new Size(300, 300));
}

/** The point inside the chevron, at the right end of the field. @return array{float, float} */
function chevron(ComboBox $c): array
{
    $b = $c->bounds();

    return [$b->x + $b->w - 6, $b->y + $b->h / 2];
}

it('is created as TKComboBox: value and options', function () {
    $c = new ComboBox('fruit', 'pear', ['apple', 'banana']);

    expect($c->value())->toBe('pear')->and($c->options())->toBe(['apple', 'banana'])->and($c->completions())->toBe([]);
});

it('opens the options from the chevron; a chosen row sets the value and posts SelectionChanged once, keeping focus', function () {
    $ui = comboUi();
    $ui->focus->focus($ui->combo);

    click($ui->router, ...chevron($ui->combo));
    settleCombo($ui);
    expect($ui->combo->list()->isOpen())->toBeTrue()->and($ui->focus->focused())->toBe($ui->combo);

    $r = $ui->combo->list()->rowRect(1);
    click($ui->router, $r->x + $r->w / 2, $r->y + $r->h / 2);

    expect($ui->combo->value())->toBe('banana')
        ->and($ui->combo->list()->isOpen())->toBeFalse()
        ->and($ui->loop->posted)->toHaveCount(1)
        ->and($ui->loop->posted[0])->toBeInstanceOf(SelectionChanged::class)
        ->and([$ui->loop->posted[0]->index, $ui->loop->posted[0]->option])->toBe([1, 'banana'])
        ->and($ui->focus->focused())->toBe($ui->combo);
});

it('shows completions below; Down and Enter choose one as index -1', function () {
    $ui = comboUi();
    $ui->focus->focus($ui->combo);

    $ui->combo->setCompletions(['apricot', 'avocado']);
    settleCombo($ui);
    expect($ui->combo->list()->isOpen())->toBeTrue()
        ->and($ui->combo->list()->bounds()->y)->toBeGreaterThanOrEqual($ui->combo->bounds()->y + $ui->combo->bounds()->h);

    pressKey($ui->router, Key::DOWN);
    pressKey($ui->router, Key::DOWN);
    pressKey($ui->router, Key::ENTER);

    expect($ui->combo->value())->toBe('avocado')
        ->and([$ui->loop->posted[0]->index, $ui->loop->posted[0]->option])->toBe([-1, 'avocado'])
        ->and($ui->combo->list()->isOpen())->toBeFalse();
});

it('posts TextSubmitted for Return with nothing highlighted', function () {
    $ui = comboUi();
    $ui->focus->focus($ui->combo);
    $ui->combo->setCompletions(['apricot']);

    pressKey($ui->router, Key::ENTER);

    expect($ui->loop->posted[0])->toBeInstanceOf(TextSubmitted::class)->and($ui->loop->posted[0]->value)->toBe('pear');
});

it('hides the list on Escape, setValue, setCompletions([]) and focus leaving', function (string $how) {
    $ui = comboUi();
    $ui->focus->focus($ui->combo);
    $ui->combo->setCompletions(['apricot']);
    settleCombo($ui);
    expect($ui->combo->list()->isOpen())->toBeTrue();

    match ($how) {
        'escape' => pressKey($ui->router, Key::ESCAPE),
        'setValue' => $ui->combo->setValue('kiwi'),
        'no completions' => $ui->combo->setCompletions([]),
        'focus leaving' => $ui->focus->focus(null),
    };

    expect($ui->combo->list()->isOpen())->toBeFalse()->and($ui->loop->posted)->toBe([]);
})->with(['escape', 'setValue', 'no completions', 'focus leaving']);
