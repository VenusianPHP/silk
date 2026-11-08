<?php

use Silk\Contracts\Look\FontSpec;
use Silk\Text\CaretGeometry;
use Silk\Text\TextMeasurer;
use Venusian\Surface\Tests\Support\Fakes\FakeFontDriver;


it('is monotonic and round-trips on grapheme boundaries', function () {
    skipWithoutFontExts();
    $text = "Wave e\u{0301} AV";
    $g = new CaretGeometry(freshDriver()->shape(TextMeasurer::pattern(new FontSpec), 13.0, $text));
    $stops = $g->stops();

    expect(array_keys($stops))->toBe([0, 1, 2, 3, 4, 5, 8, 9, 10, 11]);
    $last = -1.0;
    foreach ($stops as $byte => $x) {
        expect($x)->toBeGreaterThan($last)->and($g->byteAt($g->xAt($byte)))->toBe($byte);
        $last = $x;
    }
    expect($g->xAt(0))->toBe(0.0)->and($g->byteAt(-50))->toBe(0)->and($g->byteAt(9999))->toBe(strlen($text));
});

it('splits a ligature cluster by grapheme', function () {
    skipWithoutFontExts();
    $driver = freshDriver();
    foreach (['Times New Roman', 'Georgia', 'Helvetica Neue', 'Baskerville'] as $family) {
        $run = $driver->shape(TextMeasurer::pattern(new FontSpec($family)), 13.0, 'fi');
        if (count($run->glyphs) === 1) {
            $g = new CaretGeometry($run);

            expect($g->xAt(1))->toEqualWithDelta($run->advance / 2, 0.01);

            return;
        }
    }
    $this->markTestSkipped('No installed face ligates "fi".');
});

it('builds from measured prefixes without a shaper', function () {
    $g = CaretGeometry::measured('abc', fn (string $s) => strlen($s) * 6.0);

    expect($g->stops())->toBe([0 => 0.0, 1 => 6.0, 2 => 12.0, 3 => 18.0])->and($g->byteAt(10))->toBe(2);
});

it('places caret stops at device glyph positions at scale 2 (Review Focus 4)', function () {
    $font = (new TextMeasurer(new FakeFontDriver))->font(new FontSpec('Inter', 13.0));
    $run = $font->shape(26.0, 'abc');
    $g = new CaretGeometry($run);

    expect($g->stops())->toBe([0 => 0.0, 1 => $run->glyphs[1]->x, 2 => $run->glyphs[2]->x, 3 => $run->advance])
        ->and($g->stops()[1])->toBe(13.0);
});
