<?php

use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Mail\TimelineEdited;
use Silk\Contracts\Mail\TimelineItemChanged;
use Silk\Contracts\Mail\TimelineItemRemoved;
use Silk\Contracts\Mail\TimelineSelectionChanged;
use Silk\Contracts\Mail\TimelineTimeChanged;
use Silk\Looks\Canvas\CanvasPreset;
use Silk\Widgets\TimelineItem;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\NutsAndBolts\Color;

it('keeps tracks and items with shared ids, and a removed track takes its items', function () {
    $ui = timelineUi();
    expect([$ui->video, $ui->audio, $ui->a])->toBe([1, 2, 3])
        ->and($ui->tl->itemType($ui->key))->toBe(TimelineItem::Key)->and($ui->tl->itemTrack($ui->marker))->toBe(0)
        ->and($ui->tl->itemCount())->toBe(4);

    $ui->tl->setItem($ui->a, $ui->audio, -2.0, 1.5);
    expect([$ui->tl->itemTrack($ui->a), $ui->tl->itemStart($ui->a), $ui->tl->itemLength($ui->a)])->toBe([$ui->audio, 0.0, 1.5]);
    $ui->tl->removeTrack($ui->audio);
    expect($ui->tl->itemCount())->toBe(2)->and($ui->tl->trackCount())->toBe(1);
});

it('steps the ruler as Raven does, with frames and without', function () {
    $ui = timelineUi();
    expect($ui->tl->majorStep(0.01))->toBe([1.0, 5])          // 84 px wanted: 0.84 s, 1 s in 5
        ->and($ui->tl->majorStep(0.001))->toBe([0.1, 5]);
    $ui->tl->setFrameRate(25);
    expect($ui->tl->majorStep(0.001))->toBe([5 / 25, 5])       // frames first: 0.084 s wanted, 2 frames are 0.08: 5 frames in 5
        ->and($ui->tl->majorStep(0.01))->toBe([1.0, 1]);       // 25 marks of 0.04 s closer than 5 px: none
});

it('writes times as the ruler does', function () {
    $ui = timelineUi();
    expect($ui->tl->formatTime(75.0, 5.0))->toBe('1:15')->and($ui->tl->formatTime(3.26, 0.5))->toBe('3.3s')
        ->and($ui->tl->formatTime(65.25, 0.05))->toBe('1:05.25')->and($ui->tl->formatTime(4000.0, 60.0))->toBe('1:06:40');
    $ui->tl->setFrameRate(25);
    expect($ui->tl->formatTime(61.52, 1.0))->toBe('1:01:13');
});

it('scrubs the playhead on the ruler, snapping to the items, and posts the time', function () {
    $ui = timelineUi();
    $b = $ui->tl->bounds();
    tlDrag($ui, [$ui->tl->xOf(2.97), $b->y + 20], [$ui->tl->xOf(4.98), $b->y + 20]);

    expect($ui->tl->time())->toBe(5.0)->and(array_slice(timelineMail($ui, TimelineTimeChanged::class), 0, 1))->toBe([3.0]);
});

it('moves the selection snapping to an end, as a whole across tracks, and Escape puts it back', function () {
    $ui = timelineUi();
    [$x, $y] = $ui->tl->pointAt(2.0, $ui->video);
    // A (1..3) dropped near B's start: its end snaps to 5
    tlDrag($ui, [$x, $y], [$ui->tl->xOf(3.95), $y]);
    expect($ui->tl->itemStart($ui->a))->toBe(3.0)->and(timelineMail($ui, TimelineEdited::class))->toHaveCount(1)
        ->and(timelineMail($ui, TimelineItemChanged::class))->not->toBeEmpty();

    [$x, $y] = $ui->tl->pointAt(4.0, $ui->video);
    $ui->router->dispatch(new SilkEvent(EventType::MouseDown, x: $x, y: $y, button: Surface\Contracts\HumanInput\MouseButton::LEFT, clicks: 1));
    $ui->router->dispatch(new SilkEvent(EventType::MouseMove, x: $x + 60, y: $ui->tl->pointAt(4.0, $ui->audio)[1]));
    expect($ui->tl->itemTrack($ui->a))->toBe($ui->audio);
    $ui->router->dispatch(new SilkEvent(EventType::KeyDown, key: Key::ESCAPE));
    expect([$ui->tl->itemStart($ui->a), $ui->tl->itemTrack($ui->a)])->toBe([3.0, $ui->video]);
});

it('stretches a clip by its right end (Alt: freely; else to the ruler\'s marks), not on a locked track', function () {
    $ui = timelineUi();
    [, $y] = $ui->tl->pointAt(0, $ui->video);
    tlDrag($ui, [$ui->tl->xOf(7.0) - 2, $y], [$ui->tl->xOf(8.5), $y], new Modifiers(alt: true));
    expect($ui->tl->itemLength($ui->b))->toEqualWithDelta(3.5, 1e-9);
    tlDrag($ui, [$ui->tl->xOf(8.5) - 2, $y], [$ui->tl->xOf(9.3), $y]);
    $length = $ui->tl->itemLength($ui->b);
    expect(fmod($length + 5.0, 0.5))->toEqualWithDelta(0.0, 1e-9);

    $ui->tl->setTrackLocked($ui->video);
    tlDrag($ui, [$ui->tl->xOf(5.0 + $length) - 2, $y], [$ui->tl->xOf(9.9), $y], new Modifiers(alt: true));
    expect($ui->tl->itemLength($ui->b))->toBe($length);
});

it('selects by a rectangle in empty space, adds with Shift, Ctrl+A takes all', function () {
    $ui = timelineUi();
    [, $yv] = $ui->tl->pointAt(0, $ui->video);
    [, $ya] = $ui->tl->pointAt(0, $ui->audio);
    tlDrag($ui, [$ui->tl->xOf(0.2), $yv - 10], [$ui->tl->xOf(2.5), $ya]);
    expect($ui->tl->selection())->toBe([$ui->a, $ui->key])->and(timelineMail($ui, TimelineSelectionChanged::class))->not->toBeEmpty();

    $ui->router->dispatch(new SilkEvent(EventType::KeyDown, key: Key::A, modifiers: new Modifiers(ctrl: true)));
    expect($ui->tl->selection())->toBe([$ui->a, $ui->b, $ui->key, $ui->marker]);
});

it('mutes and locks with the header toggles, one mail each', function () {
    $ui = timelineUi();
    $h = (new ReflectionMethod($ui->tl, 'layout'))->invoke($ui->tl);
    [, $y] = $ui->tl->pointAt(0, $ui->video);
    $right = $h['headers']->x + $h['headers']->w - 8.0 * $h['unit'];
    click($ui->router, $right - $h['toggle'] * 1.5 - 3.0 * $h['unit'], $y);
    click($ui->router, $right - $h['toggle'] * 0.5, $y);

    expect($ui->tl->isTrackMuted($ui->video))->toBeTrue()->and($ui->tl->isTrackLocked($ui->video))->toBeTrue()
        ->and(timelineMail($ui, TimelineEdited::class))->toHaveCount(2);
});

it('deletes the selection only when removable, never on a locked track', function () {
    $ui = timelineUi();
    $ui->tl->select($ui->a)->select($ui->key, true);
    $ui->router->dispatch(new SilkEvent(EventType::KeyDown, key: Key::DELETE));
    expect($ui->tl->itemCount())->toBe(4);

    $ui->tl->setRemovable()->setTrackLocked($ui->audio);
    $ui->router->dispatch(new SilkEvent(EventType::KeyDown, key: Key::DELETE));
    expect($ui->tl->itemCount())->toBe(3)->and(timelineMail($ui, TimelineItemRemoved::class))->toBe([$ui->a]);
});

it('plays on the frame clock, follows with the view, stops at the end or loops', function () {
    $ui = timelineUi();
    $ui->tl->setView(0, 2)->setTime(1.5)->setPlaying();
    tlRun($ui, 1.0);
    expect($ui->tl->time())->toEqualWithDelta(2.5, 0.02)->and($ui->tl->view()[0])->toBeGreaterThan(0.0);

    $ui->tl->setTime(9.8);
    tlRun($ui, 0.5);
    expect($ui->tl->time())->toBe(10.0)->and($ui->tl->isPlaying())->toBeFalse();

    $ui->tl->setLoop()->setTime(9.8)->setPlaying();
    tlRun($ui, 0.5);
    expect($ui->tl->time())->toEqualWithDelta(0.3, 0.02)->and($ui->tl->isPlaying())->toBeTrue();
});

it('zooms around the pointer with Ctrl + wheel, the view glides there', function () {
    $ui = timelineUi();
    $x = $ui->tl->xOf(5.0);
    $ui->router->dispatch(new SilkEvent(EventType::Wheel, x: $x, y: $ui->tl->pointAt(5, $ui->video)[1], wheelY: 3, modifiers: new Modifiers(ctrl: true)));
    tlRun($ui, 1.0);
    edPaint((object) ['ed' => $ui->tl]); // (what is drawn is what the last paint drew)
    [$start, $end] = $ui->tl->view();

    expect($end - $start)->toEqualWithDelta(10 * 0.82 ** 3, 1e-9)->and($ui->tl->xOf(5.0))->toEqualWithDelta($x, 0.5);
});

it('paints from the canvas look: a preset\'s rows, overrides, track and item styles', function () {
    $ui = timelineUi();
    $ui->tl->setPreset(CanvasPreset::Neon);
    $body = edPaint((object) ['ed' => $ui->tl])[0];
    expect($body[0])->toBe('rect')->and($body[1][1]->toHex())->toBe(Color::hex('#06060B')->toHex());

    expect($ui->tl->setPartStyle('ClipShape', 'Solid'))->toBeTrue()->and($ui->tl->setPartStyle('Clip.Glow', '-1'))->toBeFalse()
        ->and($ui->tl->setPartStyle('Clip.Nonsense', '1'))->toBeFalse()
        ->and($ui->tl->setTrackStyle($ui->video, 'Clip.Background', '#123456'))->toBeTrue()
        ->and($ui->tl->setItemStyle($ui->b, 'Background', '#654321'))->toBeTrue()
        ->and($ui->tl->setPartStyle('Look', 'Blender'))->toBeTrue()->and($ui->tl->preset())->toBe(CanvasPreset::Blender);
    $fills = array_map(fn ($c) => $c[1][1]->toHex(), array_filter(edPaint((object) ['ed' => $ui->tl]), fn ($c) => $c[0] === 'rect'));
    // a solid clip is its colour mixed 0.08 toward black
    expect($fills)->toContain(Silk\Painter\RavenPaint::mix(Color::hex('#123456'), new Color(0, 0, 0), 0.08)->toHex())
        ->and($fills)->toContain(Silk\Painter\RavenPaint::mix(Color::hex('#654321'), new Color(0, 0, 0), 0.08)->toHex());
});

it('holds 5,000 items in under 6 MB', function () {
    $ui = timelineUi();
    $ui->tl->setDuration(10000);
    gc_collect_cycles();
    $before = memory_get_usage();
    for ($i = 0; $i < 5000; $i++) {
        $ui->tl->addClip($ui->video, $i * 2.0, 1.0, "c{$i}");
    }
    edPaint((object) ['ed' => $ui->tl]);

    // an item is a hash of 12 (~ 640 B with its strings) plus its lift spring when drawn: measured 4.95 MB
    expect(memory_get_usage() - $before)->toBeLessThan(6 * 1024 * 1024);
});
