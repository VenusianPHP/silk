<?php

use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Text\Translator;
use Silk\Text\Dates;
use Silk\Text\EnglishTranslator;
use Silk\Widgets\DatePicker;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;

it('formats a date as Raven does', function () {
    $t = new EnglishTranslator;
    $d = new DateTimeImmutable('2026-03-04');

    expect(Dates::format('YYYY-MM-DD', $d, $t))->toBe('2026-03-04')
        ->and(Dates::format('dddd D MMMM YYYY', $d, $t))->toBe('Wednesday 4 March 2026')
        ->and(Dates::format('ddd, MMM D', $d, $t))->toBe('Wed, Mar 4')
        ->and(Dates::format('M/D', $d, $t))->toBe('3/4');
});

it('shows today while the date is null, and date() stays null (TKDatepicker)', function () {
    $ui = dateUi();
    expect($ui->picker->date())->toBeNull()->and($ui->picker->text())->toBe((new DateTimeImmutable('today'))->format('Y-m-d'));
});

it('opens the calendar on the date\'s month and picks 29 February 2028 on the release, posting once and closing', function () {
    $ui = dateUi(new DateTimeImmutable('2028-02-10'));
    $cal = openCalendar($ui);
    expect($cal->isOpen())->toBeTrue()->and($cal->month()->format('Y-m-d'))->toBe('2028-02-01');
    click($ui->router, ...centre_of($cal->cellRect(new DateTimeImmutable('2028-02-29'))));

    expect($ui->picker->date()->format('Y-m-d'))->toBe('2028-02-29')->and(dateMail($ui))->toBe(['2028-02-29'])
        ->and($cal->isOpen())->toBeFalse()->and($ui->picker->text())->toBe('2028-02-29');
});

it('picks nothing on a release that did not start on the calendar', function () {
    $ui = dateUi(new DateTimeImmutable('2028-02-10'));
    $cal = openCalendar($ui);
    [$x, $y] = centre_of($cal->cellRect(new DateTimeImmutable('2028-02-11')));
    $cal->handle(new SilkEvent(EventType::MouseUp, x: $x, y: $y, button: MouseButton::LEFT));

    expect(dateMail($ui))->toBe([]);
});

it('starts the week on the picker\'s first day, Sunday by default', function () {
    $ui = dateUi(new DateTimeImmutable('2026-02-10'));
    expect(openCalendar($ui)->gridStart()->format('Y-m-d'))->toBe('2026-02-01');
    pressKey($ui->router, Key::ESCAPE);
    settleAll($ui);
    $ui->picker->setFirstDayOfWeek(1);
    expect(openCalendar($ui)->gridStart()->format('Y-m-d'))->toBe('2026-01-26');
});

it('moves the cursor a day, a week, a month, a year with Shift, to the ends with Home and End, and picks with Enter', function () {
    $ui = dateUi(new DateTimeImmutable('2026-03-31'));
    $cal = openCalendar($ui);
    pressKey($ui->router, Key::RIGHT);
    pressKey($ui->router, Key::DOWN);
    expect($cal->cursor()->format('Y-m-d'))->toBe('2026-04-08');
    $ui->router->dispatch(new SilkEvent(EventType::KeyDown, key: Key::PAGE_UP, modifiers: new Modifiers(shift: true)));
    expect($cal->cursor()->format('Y-m-d'))->toBe('2025-04-08');
    pressKey($ui->router, Key::END);
    pressKey($ui->router, Key::ENTER);

    expect(dateMail($ui))->toBe(['2025-04-30']);
});

it('refuses days outside the range, which a click cannot pick', function () {
    $ui = dateUi(new DateTimeImmutable('2026-03-10'));
    $ui->picker->setMinDate(new DateTimeImmutable('2026-03-05'))->setMaxDate(new DateTimeImmutable('2026-03-20'));
    $cal = openCalendar($ui);
    click($ui->router, ...centre_of($cal->cellRect(new DateTimeImmutable('2026-03-25'))));

    expect(dateMail($ui))->toBe([])->and($cal->isOpen())->toBeTrue();
});

it('steps a day with Up and Down on the field, and opens with Alt+Down, Enter or Space', function () {
    $ui = dateUi(new DateTimeImmutable('2026-03-10'));
    $ui->focus->focus($ui->picker);
    pressKey($ui->router, Key::DOWN);
    pressKey($ui->router, Key::DOWN);
    pressKey($ui->router, Key::UP);
    expect(dateMail($ui))->toBe(['2026-03-11', '2026-03-12', '2026-03-11'])->and($ui->picker->isOpen())->toBeFalse();
    $ui->router->dispatch(new SilkEvent(EventType::KeyDown, key: Key::DOWN, modifiers: new Modifiers(alt: true)));
    expect($ui->picker->isOpen())->toBeTrue();
});

it('names the months and days through the translator', function () {
    $ui = dateUi(new DateTimeImmutable('2026-03-04'));
    $ui->tree->setTranslator(new class implements Translator
    {
        public function text(string $key, string $english): string
        {
            return ['Raven.Month3' => 'März', 'Raven.Day3' => 'Mittwoch'][$key] ?? $english;
        }
    });
    $ui->picker->setFormat('dddd D MMMM');

    expect($ui->picker->text())->toBe('Mittwoch 4 März');
});

it('opens inside the host from the bottom-right corner', function () {
    $ui = dateUi(new DateTimeImmutable('2026-03-04'), corner: true);
    $ui->tree->layout(new Size(300, 300));
    $c = openCalendar($ui)->bounds();

    expect($c->x)->toBeGreaterThanOrEqual(0.0)->and($c->x + $c->w)->toBeLessThanOrEqual(300.0)
        ->and($c->y)->toBeGreaterThanOrEqual(0.0)->and($c->y + $c->h)->toBeLessThanOrEqual(300.0);
});

it('posts nothing for setDate', function () {
    $ui = dateUi();
    $ui->picker->setDate(new DateTimeImmutable('2030-01-01'));

    expect($ui->loop->posted)->toBe([])->and($ui->picker->text())->toBe('2030-01-01');
});
