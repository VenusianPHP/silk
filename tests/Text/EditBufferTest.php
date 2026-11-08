<?php

use Silk\Text\EditBuffer;
use Silk\Text\WordBreaks;

beforeEach(function () {
    if (! extension_loaded('intl')) {
        $this->markTestSkipped('needs intl');
    }
});

it('steps over e + U+0301 as one grapheme', function () {
    $b = new EditBuffer("e\u{0301}x");
    $b->home(false);
    $b->moveRight(false, false);

    expect($b->selection()->caret)->toBe(3);
    $b->deleteBackward();
    expect($b->text())->toBe('x');
});

it('moves by words: 5, then 11, and back', function () {
    $b = new EditBuffer('hello world');
    $b->home(false);
    $b->moveRight(true, false);
    expect($b->selection()->caret)->toBe(5);
    $b->moveRight(true, false);
    expect($b->selection()->caret)->toBe(11);
    $b->moveLeft(true, false);
    expect($b->selection()->caret)->toBe(6);
    expect(WordBreaks::previous('hello world', 6))->toBe(0);
});

it('extends and collapses a selection', function () {
    $b = new EditBuffer('hello world');
    $b->end(false);
    $b->moveLeft(true, true);
    expect($b->selection()->start())->toBe(6)->and($b->selection()->end())->toBe(11)->and($b->selectedText())->toBe('world');
    $b->moveLeft(false, false);
    expect($b->selection()->isEmpty())->toBeTrue()->and($b->selection()->caret)->toBe(6);
});

it('replaces the selection on insert', function () {
    $b = new EditBuffer('hello');
    $b->selectAll();
    $b->insert('bye');

    expect($b->text())->toBe('bye')->and($b->selection()->caret)->toBe(3);
});

it('undoes consecutive typing as one step and redoes it', function () {
    $b = new EditBuffer('x');
    $b->end(false);
    foreach (['a', 'b', 'c'] as $ch) {
        $b->insert($ch, typing: true);
    }
    expect($b->undo())->toBeTrue()->and($b->text())->toBe('x');
    expect($b->redo())->toBeTrue()->and($b->text())->toBe('xabc');
    expect($b->undo())->toBeTrue()->and($b->undo())->toBeFalse()->and($b->text())->toBe('x');
});

it('starts a new undo step after a move', function () {
    $b = new EditBuffer('');
    $b->insert('a', typing: true);
    $b->home(false);
    $b->insert('b', typing: true);
    $b->undo();

    expect($b->text())->toBe('a');
});

it('selects the word at a byte', function () {
    $b = new EditBuffer('hello world');
    $b->selectWordAt(8);

    expect($b->selectedText())->toBe('world');
});

it('deletes words and to the end', function () {
    $b = new EditBuffer('one two three');
    $b->end(false);
    $b->deleteWordBackward();
    expect($b->text())->toBe('one two ');
    $b->home(false);
    $b->deleteToEnd();
    expect($b->text())->toBe('')->and($b->killed())->toBe('one two ');
});
