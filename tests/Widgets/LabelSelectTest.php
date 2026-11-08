<?php

use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Role;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\Windows\Input\EditCommand;
use Venusian\Silk\Tests\Support\SpyPainter;

beforeEach(function () {
    if (! extension_loaded('intl')) {
        $this->markTestSkipped('needs intl');
    }
});

/** The x of byte $k of a one-line plain label. */
$xAt = fn (object $ui, int $k) => $ui->label->bounds()->x + $ui->tree->measurer()->measure(substr($ui->label->text(), 0, $k), $ui->look->font())->w;

it('takes the pointer only when selectable, and is focused by a click but skipped by Tab', function () {
    $ui = selectUi('alpha beta');
    [$cx, $cy] = centre($ui->label);
    click($ui->router, $cx, $cy);

    expect($ui->label->hits(1, 1))->toBeTrue()->and($ui->focus->focused())->toBe($ui->label)
        ->and($ui->label->isTabStop())->toBeFalse()
        ->and((new Silk\Widgets\Label('x', 'y'))->hits(1, 1))->toBeFalse();
    $ui->focus->focus(null);
    $ui->focus->next();
    expect($ui->focus->focused())->toBeNull();

    $ui->label->setSelectable(false);
    expect($ui->label->isFocusable())->toBeFalse()->and($ui->label->isTabStop())->toBeTrue();
});

it('selects by characters on a drag, and extends with Shift', function () use ($xAt) {
    $ui = selectUi('alpha beta gamma');
    $y = centre($ui->label)[1];
    pressDrag($ui, $xAt($ui, 0) + 1, $y, $xAt($ui, 5), $y);
    expect($ui->label->selectedText())->toBe('alpha');

    pressDrag($ui, $xAt($ui, 10), $y, shift: true);
    expect($ui->label->selectedText())->toBe('alpha beta');
});

it('selects words on a double click and drag, paragraphs on a triple click', function () use ($xAt) {
    $ui = selectUi('alpha beta gamma');
    $y = centre($ui->label)[1];
    pressDrag($ui, $xAt($ui, 7), $y, clicks: 2);
    expect($ui->label->selectedText())->toBe('beta');

    pressDrag($ui, $xAt($ui, 7), $y, $xAt($ui, 13), $y, clicks: 2);
    expect($ui->label->selectedText())->toBe('beta gamma');

    pressDrag($ui, $xAt($ui, 2), $y, clicks: 3);
    expect($ui->label->selectedText())->toBe('alpha beta gamma');
});

it('copies through its clipboard, selects all, and clears on Escape and on losing focus', function () use ($xAt) {
    $ui = selectUi('alpha beta');
    $y = centre($ui->label)[1];
    pressDrag($ui, $xAt($ui, 6), $y, clicks: 2);
    $ui->router->dispatch(new SilkEvent(EventType::Edit, command: EditCommand::Copy));
    expect($ui->clip->text)->toBe('beta');

    $ui->router->dispatch(new SilkEvent(EventType::Edit, command: EditCommand::SelectAll));
    expect($ui->label->selection())->toBe([0, 10]);
    pressKey($ui->router, Key::ESCAPE);
    expect($ui->label->selection())->toBe([0, 0]);

    $ui->label->selectAll();
    $ui->focus->focus(null);
    expect($ui->label->selectedText())->toBe('');
});

it('paints the selection in Selection behind the text', function () use ($xAt) {
    $ui = selectUi('alpha beta');
    pressDrag($ui, $xAt($ui, 0) + 1, centre($ui->label)[1], $xAt($ui, 5), centre($ui->label)[1]);
    $p = new SpyPainter;
    $ui->label->render($p);
    $kinds = array_column($p->calls, 0);
    $rect = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'rect'))[0];

    expect($rect[1][1])->toEqual($ui->look->color(Role::Selection))
        ->and($rect[1][0]->w)->toEqualWithDelta($xAt($ui, 5) - $xAt($ui, 0), 0.01)
        ->and(array_search('rect', $kinds))->toBeLessThan(array_search('text', $kinds));
});

it('selects the text shown in a Markdown label and copies it without the marks', function () {
    $ui = selectUi("**Bold** words\n\n1. item", true);
    $ui->label->selectAll();
    $ui->router->dispatch(new SilkEvent(EventType::Edit, command: EditCommand::Copy));

    expect($ui->clip->text)->toBeNull(); // not focused yet: the Edit went nowhere
    [$cx, $cy] = centre($ui->label);
    pressDrag($ui, $cx, $cy, clicks: 3);
    $ui->label->selectAll();
    $ui->router->dispatch(new SilkEvent(EventType::Edit, command: EditCommand::Copy));
    expect($ui->clip->text)->toBe("Bold words\nitem");
});

it('follows a link on a click instead of selecting', function () {
    $ui = selectUi('go [there](u) now', true);
    $run = array_values(array_filter($ui->label->markdownLayout()->runs, fn ($r) => $r['link'] === 0))[0];
    $b = $ui->label->bounds();
    click($ui->router, $b->x + $run['x'] + 3, $b->y + $run['lineY'] + 2);

    expect(linkMail($ui))->toBe(['u'])->and($ui->label->selection())->toBe([0, 0]);
});

it('cuts a plain label at each newline: one text call a line, a line height apart, as label.c does', function () {
    $ui = selectUi("first\nsecond\n");
    $p = new SpyPainter;
    $ui->label->render($p);
    $texts = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'text'));
    $height = $ui->tree->measurer()->metrics($ui->look->font())['lineHeight'];

    expect(array_map(fn ($c) => $c[1][0], $texts))->toBe(['first', 'second'])
        ->and($texts[1][1][2] - $texts[0][1][2])->toBe($height)
        ->and($ui->label->bounds()->h)->toBe(3 * $height); // the "\n" at the end makes one more, empty, line
});

it('selects across the lines of a plain label and copies the newline', function () {
    $ui = selectUi("first\nsecond");
    $b = $ui->label->bounds();
    $height = $ui->tree->measurer()->metrics($ui->look->font())['lineHeight'];
    pressDrag($ui, $b->x + 1, $b->y + 2, $b->x + 200, $b->y + $height + 2);

    expect($ui->label->selectedText())->toBe("first\nsecond");
});
