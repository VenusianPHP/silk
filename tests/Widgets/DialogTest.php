<?php

use Silk\Contracts\Geometry\Size;
use Silk\Widgets\Dialog;
use Silk\Widgets\MessageBox;
use Surface\Contracts\Windows\Mail\Services\AlertAnswered;
use Venusian\Silk\Tests\Support\SpyPainter;

function settleUi(object $ui): void
{
    for ($i = 0; $i < 200 && $ui->tree->advance(1 / 60); $i++) {
    }
    $ui->tree->layout(new Size(300, 300));
}

it('paints its veil at 0.42 x shown, then a centred card rising and growing in', function () {
    $ui = silkUi(fn ($root) => $root->button('ok', 'OK'));
    $dialog = (new Dialog('confirm'))->in($ui->tree);
    $dialog->add(new Silk\Core\Layout\Spacer('body', 100, 60));
    $dialog->openAt(0, 0);
    $ui->tree->layout(new Size(300, 300));
    $ui->tree->advance(1 / 60);
    $shown = $dialog->shown();
    $p = new SpyPainter;

    $dialog->render($p);

    [$veilMethod, $veil] = $p->calls[0];
    $transform = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'pushTransform'))[0][1];
    $card = $dialog->bounds();
    expect($dialog->isModal())->toBeTrue()
        ->and($veilMethod)->toBe('rect')
        ->and($veil[0])->toEqual(new Silk\Contracts\Geometry\Rect(0, 0, 300, 300))
        ->and($veil[1]->alpha)->toEqualWithDelta(0.42 * $shown, 1e-9)
        ->and($card->x + $card->w / 2)->toBe(150.0)
        ->and($card->y + $card->h / 2)->toBe(150.0)
        ->and($transform[0])->toEqualWithDelta(0.9 + 0.1 * $shown, 1e-9);
});

it('posts AlertAnswered once with the pressed button, and closes', function () {
    $ui = silkUi(fn ($root) => $root->button('ok', 'OK'));
    $box = MessageBox::show($ui->tree, 'Quit?', 'Unsaved changes will be lost.', ['Quit', 'Cancel']);
    settleUi($ui);
    $cancel = $box->answerButton(1);

    click($ui->router, ...centre($cancel));

    expect($ui->loop->posted)->toHaveCount(1)
        ->and($ui->loop->posted[0])->toBeInstanceOf(AlertAnswered::class)
        ->and($ui->loop->posted[0]->button)->toBe(1)
        ->and($ui->loop->posted[0]->id)->toBe($box->name())
        ->and($box->isOpen())->toBeFalse();
});

it('takes a click outside its card without closing (modal)', function () {
    $ui = silkUi(fn ($root) => $root->button('ok', 'OK'));
    $box = MessageBox::show($ui->tree, 'Quit?', 'Sure?', ['OK']);
    settleUi($ui);

    click($ui->router, 2, 2);

    expect($box->isOpen())->toBeTrue()->and($ui->loop->posted)->toBe([]);
});
