<?php

use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Mail\ColorChanged;
use Silk\Widgets\ColorPicker;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\NutsAndBolts\Color;
use Venusian\Silk\Tests\Support\SpyPainter;

it('round-trips HSV and RGB at the corners and keeps a grey\'s hue', function () {
    foreach ([[0, 0, 0], [1, 0, 0], [0, 1, 0], [0, 0, 1], [1, 1, 0], [0, 1, 1], [1, 0, 1], [1, 1, 1], [0.5, 0.5, 0.5]] as $rgb) {
        expect(ColorPicker::hsvToRgb(...ColorPicker::rgbToHsv(...$rgb)))->toEqualWithDelta($rgb, 1e-9);
    }
    $ui = pickerUi(Color::hex('#3366cc'));
    $hue = $ui->cp->hsv()[0];
    $ui->cp->setColor(Color::hex('#808080'));
    expect($ui->cp->hsv()[0])->toEqualWithDelta($hue, 1e-6);
});

it('sets saturation and value from the square, clamped, posting each drag step', function () {
    $ui = pickerUi();
    [$sq] = $ui->cp->areas();
    $ui->router->dispatch(new SilkEvent(EventType::MouseDown, x: $sq->x + $sq->w - 0.5, y: $sq->y + 0.5, button: MouseButton::LEFT, clicks: 1));
    $ui->router->dispatch(new SilkEvent(EventType::MouseMove, x: $sq->x - 50, y: $sq->y + $sq->h + 50, button: MouseButton::LEFT));
    $ui->router->dispatch(new SilkEvent(EventType::MouseUp, x: $sq->x - 50, y: $sq->y + $sq->h + 50, button: MouseButton::LEFT));
    [, $s, $v] = $ui->cp->hsv();

    // the press at the top-right corner is full saturation and value; the drag past the bottom-left clamps to none
    expect([$s, $v])->toEqualWithDelta([0.0, 0.0], 1e-9)->and(colourMail($ui))->toHaveCount(2)
        ->and(colourMail($ui)[1])->toBe('#000000');
});

it('takes a code as it is typed, with opacity only from 8 digits, and refuses bad ones', function () {
    $ui = pickerUi();
    $ui->cp->hexField()->setValue('');
    $ui->focus->focus($ui->cp->hexField());
    foreach (str_split('#3366ccff') as $ch) {
        $ui->router->dispatch(new SilkEvent(EventType::Text, text: $ch));
    }
    expect($ui->cp->code())->toBe('#3366CC');
    $ui->cp->hexField()->setValue('#12');
    pressKey($ui->router, Key::ENTER);
    expect($ui->cp->code())->toBe('#3366CC')->and($ui->cp->hexField()->value())->toBe('#3366CC');
    $ui->cp->hexField()->setValue('');
    foreach (str_split('11223380') as $ch) {
        $ui->router->dispatch(new SilkEvent(EventType::Text, text: $ch));
    }
    expect($ui->cp->code())->toBe('#11223380');
});

it('keeps the numbers in step, RGB or HSV, and takes them back', function () {
    $ui = pickerUi(Color::hex('#ff8000'));
    [$r, $g, $b, $a] = $ui->cp->numberFields();
    expect([$r->value(), $g->value(), $b->value(), $a->value()])->toBe([255.0, 128.0, 0.0, 100.0]);
    click($ui->router, ...centre($ui->cp->modeButton()));
    expect($ui->cp->modeButton()->label())->toBe('HSV')->and($r->value())->toBe(30.0)->and($g->value())->toBe(100.0);
    $ui->focus->focus($b);
    pressKey($ui->router, Key::DOWN);
    expect(colourMail($ui))->toHaveCount(1)->and(round($ui->cp->hsv()[2], 2))->toBe(0.99);
});

it('takes a swatch exactly, and goes back to the colour before from the preview', function () {
    $ui = pickerUi(Color::hex('#3366cc'));
    $ui->cp->setSwatches([Color::hex('#ff0000'), Color::hex('#00ff0080')]);
    $ui->tree->layout(new Size(300, 600));
    click($ui->router, ...centre_of($ui->cp->swatchRect(1)));
    expect($ui->cp->code())->toBe('#00FF0080');
    [, , $pv] = $ui->cp->areas();
    click($ui->router, $pv->x + 2, $pv->y + 2);
    expect($ui->cp->code())->toBe('#3366CC')->and(colourMail($ui))->toBe(['#00ff0080', '#3366cc']);
});

it('makes its three textures once, however the hue moves', function () {
    $ui = pickerUi();
    $ui->cp->render(new SpyPainter);
    $built = ColorPicker::$built;
    [, $hue] = $ui->cp->areas();
    $ui->router->dispatch(new SilkEvent(EventType::MouseDown, x: $hue->x + 5, y: $hue->y + 5, button: MouseButton::LEFT, clicks: 1));
    for ($i = 0; $i < 10; $i++) {
        $ui->router->dispatch(new SilkEvent(EventType::MouseMove, x: $hue->x + 10 * $i, y: $hue->y + 5, button: MouseButton::LEFT));
        $ui->cp->render(new SpyPainter);
    }

    expect(ColorPicker::$built)->toBe($built)->and($built)->toBeLessThanOrEqual(3);
});

it('in popup mode opens a second picker that keeps the swatch in step; the swatch posts', function () {
    $ui = pickerUi(Color::hex('#3366cc'));
    $ui->cp->setPopup(true);
    $ui->tree->layout(new Size(300, 600));
    click($ui->router, ...centre($ui->cp));
    settleAll($ui);
    expect($ui->cp->isPopupOpen())->toBeTrue();
    $inner = $ui->cp->inner();
    $ui->tree->layout(new Size(300, 600));
    [, , $pv] = $inner->areas();
    $inner->setSwatches([Color::hex('#ffcc00')]);
    $ui->tree->layout(new Size(300, 600));
    click($ui->router, ...centre_of($inner->swatchRect(0)));
    $mail = array_values(array_filter($ui->loop->posted, fn ($m) => $m instanceof ColorChanged));

    expect($ui->cp->code())->toBe('#FFCC00')->and($mail)->toHaveCount(1)->and($mail[0]->path)->toBe($ui->cp->path());
});

it('leaves out the alpha strip until Surface images take a tint', function () {
    $ui = pickerUi(Color::hex('#3366cc80'));
    $p = new SpyPainter;
    $ui->cp->render($p);
    $images = array_filter($p->calls, fn ($c) => $c[0] === 'image');

    expect($images)->toHaveCount(3)->and($ui->cp->code())->toBe('#3366CC80');
});
