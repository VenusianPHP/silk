<?php

use Silk\Contracts\Input\EventType;
use Silk\Hosts\CanvasInputAdapter;
use Silk\Hosts\StagedInputAdapter;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Mail\Staged;
use Surface\Contracts\Windows\Mail\View;
use Surface\Contracts\Windows\Mail\View\ScrollPhase;

dataset('canvas mail', function () {
    $m = new Modifiers(shift: true);
    $id = ['main', 'window.canvas', 'u-1'];

    return [
        'pressed' => [new View\CanvasPressed(...$id, x: 20, y: 40, button: MouseButton::LEFT, clicks: 2, modifiers: $m), EventType::MouseDown, 10.0, 20.0],
        'released' => [new View\CanvasReleased(...$id, x: 20, y: 40, button: MouseButton::LEFT, modifiers: $m), EventType::MouseUp, 10.0, 20.0],
        'dragged' => [new View\CanvasDragged(...$id, x: 6, y: 8, button: MouseButton::LEFT, modifiers: $m), EventType::MouseMove, 3.0, 4.0],
        'moved' => [new View\CanvasMoved(...$id, x: 6, y: 8, modifiers: $m), EventType::MouseMove, 3.0, 4.0],
        'entered' => [new View\CanvasEntered(...$id), EventType::Enter, null, null],
        'left' => [new View\CanvasLeft(...$id), EventType::Leave, null, null],
        'key pressed' => [new View\KeyPressed(...$id, key: Key::A, character: 'a', modifiers: $m, repeat: true), EventType::KeyDown, null, null],
        'key released' => [new View\KeyReleased(...$id, key: Key::A, modifiers: $m), EventType::KeyUp, null, null],
        'text' => [new View\TextCommitted(...$id, text: 'é'), EventType::Text, null, null],
        'focus gained' => [new View\FocusGained(...$id), EventType::FocusGained, null, null],
        'focus lost' => [new View\FocusLost(...$id), EventType::FocusLost, null, null],
    ];
});

it('maps canvas mail to its event, points to logical units at scale 2', function (object $mail, EventType $type, ?float $x, ?float $y) {
    $e = (new CanvasInputAdapter(2.0))->map($mail);

    expect($e->type)->toBe($type);
    if ($x !== null) {
        expect($e->x)->toBe($x)->and($e->y)->toBe($y);
    }
})->with('canvas mail');

it('keeps the fields', function () {
    $a = new CanvasInputAdapter(2.0);
    $id = ['main', 'window.canvas', 'u-1'];

    $down = $a->map(new View\CanvasPressed(...$id, x: 1, y: 1, button: MouseButton::RIGHT, clicks: 2, modifiers: new Modifiers(ctrl: true)));
    $key = $a->map(new View\KeyPressed(...$id, key: Key::TAB, character: '', modifiers: new Modifiers(shift: true), repeat: true));
    $text = $a->map(new View\TextCommitted(...$id, text: 'hi'));

    expect($down->button)->toBe(MouseButton::RIGHT)->and($down->clicks)->toBe(2)->and($down->modifiers->ctrl)->toBeTrue()
        ->and($key->key)->toBe(Key::TAB)->and($key->modifiers->shift)->toBeTrue()->and($key->repeat)->toBeTrue()
        ->and($text->text)->toBe('hi');
});

it('turns wheel deltas into lines at the last pointer position', function () {
    $a = new CanvasInputAdapter(2.0);
    $id = ['main', 'window.canvas', 'u-1'];
    $a->map(new View\CanvasMoved(...$id, x: 30, y: 50, modifiers: new Modifiers));

    $notch = $a->map(new View\CanvasWheeled(...$id, dx: 0, dy: -1, precise: false, modifiers: new Modifiers));
    $pad = $a->map(new View\CanvasWheeled(...$id, dx: 24, dy: -96, precise: true, modifiers: new Modifiers, phase: ScrollPhase::CHANGED));

    expect($notch->type)->toBe(EventType::Wheel)->and($notch->wheelY)->toBe(-1.0)->and($notch->x)->toBe(15.0)->and($notch->y)->toBe(25.0)
        ->and($pad->precise)->toBeTrue()->and($pad->wheelX)->toBe(0.5)->and($pad->wheelY)->toBe(-2.0)
        ->and($pad->phase)->toBe(ScrollPhase::CHANGED);
});

it('ignores mail it does not map, such as ViewResized and the other family', function () {
    $id = ['main', 'window.canvas', 'u-1'];

    expect((new CanvasInputAdapter)->map(new View\ViewResized(...$id, width: 10, height: 10)))->toBeNull()
        ->and((new CanvasInputAdapter)->map(new Staged\StagedMoved('main', x: 1, y: 1, modifiers: new Modifiers)))->toBeNull()
        ->and((new StagedInputAdapter)->map(new View\CanvasMoved(...$id, x: 1, y: 1, modifiers: new Modifiers)))->toBeNull();
});

it('maps hand-posted staged mail the same way', function () {
    $a = new StagedInputAdapter(2.0);
    $m = new Modifiers;

    expect($a->map(new Staged\StagedPressed('main', x: 20, y: 40, button: MouseButton::LEFT, clicks: 1, modifiers: $m))->type)->toBe(EventType::MouseDown)
        ->and($a->map(new Staged\StagedReleased('main', x: 20, y: 40, button: MouseButton::LEFT, modifiers: $m))->x)->toBe(10.0)
        ->and($a->map(new Staged\StagedLeft('main'))->type)->toBe(EventType::Leave)
        ->and($a->map(new Staged\StagedKeyPressed('main', key: Key::ENTER, character: '', modifiers: $m, repeat: false))->key)->toBe(Key::ENTER)
        ->and($a->map(new Staged\StagedFocusLost('main'))->type)->toBe(EventType::FocusLost);
});
