<?php

declare(strict_types=1);

require_once __DIR__.'/Support/SpyPainter.php';
require_once __DIR__.'/Support/Probes.php';
require_once __DIR__.'/Support/SpyEngine.php';
require_once __DIR__.'/Support/SpyLoop.php';

/** @param list<array{float, float}> $contour  @return array{float, float, float, float} minX, minY, maxX, maxY */
function bbox(array $contour): array
{
    $xs = array_column($contour, 0);
    $ys = array_column($contour, 1);

    return [min($xs), min($ys), max($xs), max($ys)];
}
require_once __DIR__.'/Support/WidgetHelpers.php';
require_once __DIR__.'/Support/TreeHelpers.php';
require_once __DIR__.'/Support/LookHelpers.php';
require_once __DIR__.'/Support/M2Helpers.php';
require_once __DIR__.'/Support/M5Helpers.php';

/** Whether the font exts (and intl) are loaded; real-text tests skip without them. */
function hasFontExts(): bool
{
    return extension_loaded('freetype') && extension_loaded('harfbuzz') && extension_loaded('fontconfig') && extension_loaded('intl');
}

/** Skips a test that needs the real font exts. */
function skipWithoutFontExts(): void
{
    if (! hasFontExts()) {
        test()->markTestSkipped('needs ext-freetype, ext-harfbuzz, ext-fontconfig and intl (php 8.4)');
    }
}

/** A fresh TrueType driver over the real font exts, so its caches start empty. */
function freshDriver(): Surface\Contracts\Fonts\FontDriver
{
    return new Surface\Fonts\Extended\ExtendedFontDriver;
}

// Surface's FakeFontDriver (fixed glyphs, no exts) lives in its checkout's tests, export-ignored from its package.
require_once (getenv('SURFACE_TESTS') ?: dirname(__DIR__, 2).'/surface/tests').'/Support/Fakes/FakeFontDriver.php';

/** Advances the tree until no spring moves (at most 5 s), then lays it out at 300x300. */
function settleAll(object $ui): void
{
    for ($i = 0; $i < 300 && $ui->tree->advance(1 / 60); $i++) {
    }
    $ui->tree->layout(new Silk\Contracts\Geometry\Size(300, 300));
}

function drag3(object $ui, array $from, array $to): void
{
    $ui->router->dispatch(new Silk\Contracts\Input\SilkEvent(Silk\Contracts\Input\EventType::MouseDown, x: $from[0], y: $from[1], button: Surface\Contracts\HumanInput\MouseButton::LEFT, clicks: 1));
    $ui->router->dispatch(new Silk\Contracts\Input\SilkEvent(Silk\Contracts\Input\EventType::MouseMove, x: $to[0], y: $to[1], button: Surface\Contracts\HumanInput\MouseButton::LEFT));
    $ui->router->dispatch(new Silk\Contracts\Input\SilkEvent(Silk\Contracts\Input\EventType::MouseUp, x: $to[0], y: $to[1], button: Surface\Contracts\HumanInput\MouseButton::LEFT, clicks: 1));
}
