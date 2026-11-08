<?php

use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\SyntaxToken;
use Silk\Contracts\Mail\ZoomChanged;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\Windows\Input\EditCommand as C;

/** The texts of the open proposals. @return list<string> */
$items = fn (object $ui) => array_column($ui->ed->completions()?->items ?? [], 'text');

it('proposes the language\'s words and the text\'s names after two characters, typed case first, and accepts with Enter', function () use ($items) {
    $ui = editorUi("int counter = 0;\n", 'C');
    $ui->ed->setCursor(1, 0);
    edType($ui, 'c');
    expect($ui->ed->isCompleting())->toBeFalse();

    edType($ui, 'o');
    expect($ui->ed->isCompleting())->toBeTrue()->and($items($ui))->toBe(['const', 'continue', 'counter'])
        ->and($ui->ed->completions()->items[0]['detail'])->toBe('keyword');

    edCommand($ui, C::MoveDown, C::MoveDown, C::InsertNewline);
    expect($ui->ed->line(1))->toBe('counter')->and($ui->ed->isCompleting())->toBeFalse()
        ->and(textMail($ui))->toContain("int counter = 0;\ncounter");
});

it('narrows as a word is typed, widens back on erasing, closes on anything that is not a word character', function () use ($items) {
    $ui = editorUi('', 'C');
    edType($ui, 'un');
    expect($items($ui))->toBe(['union', 'unsigned']);
    edType($ui, 's');
    expect($items($ui))->toBe(['unsigned']);
    edCommand($ui, C::DeleteBackward);
    expect($items($ui))->toBe(['union', 'unsigned']);
    edType($ui, ' ');
    expect($ui->ed->isCompleting())->toBeFalse();
});

it('lists the application\'s proposals first in its order, each word once, and wraps Up and Down', function () use ($items) {
    $ui = editorUi('', 'C');
    $ui->ed->setCompletionCallback(function (string $prefix, $ed) {
        $ed->addCompletion('while_loop', 'snippet');
        $ed->addCompletion('whatever');
        $ed->addCompletion('nope');
        $ed->addCompletion('while');
    });
    edType($ui, 'wh');
    expect($items($ui))->toBe(['while_loop', 'whatever', 'while']);

    edCommand($ui, C::MoveUp);
    expect($ui->ed->completions()->selected)->toBe(2);
    edCommand($ui, C::InsertTab);
    expect($ui->ed->value())->toBe('while');
});

it('refuses addCompletion outside a completion callback', function () {
    editorUi()->ed->addCompletion('x');
})->throws(LogicException::class);

it('proposes with Ctrl+Space even without a language or with proposing off, and Cancel closes', function () use ($items) {
    $ui = editorUi("alphabet\n");
    $ui->ed->setCompletion(false);
    $ui->ed->setCursor(1, 0);
    edType($ui, 'al');
    expect($ui->ed->isCompleting())->toBeFalse();

    $ui->router->dispatch(new SilkEvent(EventType::KeyDown, key: Key::SPACE, modifiers: new Modifiers(ctrl: true)));
    expect($items($ui))->toBe(['alphabet']);
    edCommand($ui, C::Cancel);
    expect($ui->ed->isCompleting())->toBeFalse();
});

it('posts ZoomChanged when the user zooms, not when the program does', function () {
    $ui = editorUi('text');
    $ui->ed->setZoom(2.0);
    $ui->router->dispatch(new SilkEvent(EventType::Wheel, x: 40, y: 40, wheelY: 1, modifiers: new Modifiers(ctrl: true)));
    $mail = array_values(array_filter($ui->loop->posted, fn ($m) => $m instanceof ZoomChanged));

    expect($ui->ed->zoom())->toBe(2.2)->and($mail)->toHaveCount(1)->and($mail[0]->zoom)->toBe(2.2);
});

it('colours Markdown as written, titles and strong text in bold', function () {
    $ui = editorUi("# Title\n**bold** and _em_");
    $ui->ed->setMarkdown();
    $calls = array_values(array_filter(edPaint($ui), fn ($c) => $c[0] === 'text'));
    $fonts = array_column(array_map(fn ($c) => [$c[1][0], $c[1][4]], $calls), 1, 0);

    expect($ui->ed->isMarkdown())->toBeTrue()->and($ui->ed->token(0, 2))->toBe(SyntaxToken::Heading)
        ->and($fonts['# Title']->weight)->toBe(700)->and($fonts['**bold**']->weight)->toBe(700)
        ->and($fonts['_em_']->italic)->toBeTrue();
});
