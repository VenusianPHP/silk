<?php

use Silk\Core\Providers\SilkServiceProvider;
use Silk\Contracts\Look\Role;
use Silk\Core\Silk;
use Silk\Hosts\FramePump;
use Silk\Looks\LookManager;
use Surface\Contracts\Windows\Services\SystemAccent;
use Surface\NutsAndBolts\Color;
use Voyager\Config\Repository;
use Voyager\Core\RenderedInstance;
use Voyager\NutsAndBolts\ServiceProvider;

/** @param array<string, mixed> $config silk.* keys set before the provider registers */
function silkApp(array $config = []): RenderedInstance
{
    $app = new RenderedInstance(sys_get_temp_dir().'/silk-app');
    $app->registerInstance('config', $repository = new Repository);
    foreach ($config as $key => $value) {
        $repository->set('silk.'.$key, $value);
    }
    $provider = new SilkServiceProvider($app);
    $provider->register();
    $provider->boot();

    return $app;
}

it('is discoverable through extra.venusian.providers', function () {
    $composer = json_decode(file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);

    expect($composer['extra']['venusian']['providers'])->toContain(SilkServiceProvider::class);
});

it('publishes the silk-config tag', function () {
    silkApp();

    $paths = ServiceProvider::pathsToPublish(SilkServiceProvider::class, 'silk-config');

    expect(array_keys($paths))->toHaveCount(1)
        ->and(array_key_first($paths))->toEndWith('/config/silk.php')
        ->and(reset($paths))->toEndWith('silk.php');
});

it('registers silk and its class alias as one singleton', function () {
    $app = silkApp();

    expect($app->make('silk'))->toBeInstanceOf(Silk::class)
        ->and($app->make(Silk::class))->toBe($app->make('silk'));
});

it('merges the default config', function () {
    $config = silkApp()->make('silk')->config();

    expect($config['look'])->toBe('raven-dark')
        ->and($config['scale'])->toBe(1.0)
        ->and($config['reduced_motion'])->toBeFalse()
        ->and($config)->not->toHaveKey('engine'); // no host has an engine key: Surface's drawing.default decides
});

it('binds silk.looks with the configured look in use', function () {
    $app = silkApp();

    expect($app->make('silk.looks'))->toBeInstanceOf(LookManager::class)
        ->and($app->make(LookManager::class))->toBe($app->make('silk.looks'))
        ->and($app->make('silk.looks')->current()->color(Role::Window)->toHex())->toBe('#1e1e20');
});

it('joins the frame pump to the event loop when the loop resolves', function () {
    $app = silkApp();
    $loop = new Venusian\Silk\Tests\Support\SpyLoop;
    $app->registerSingleton('event-loop', fn () => $loop);

    $app->make('event-loop');

    expect($loop->resources)->toHaveKey('silk')
        ->and($loop->resources['silk'])->toBeInstanceOf(FramePump::class);
});

/** A toolkit-windows stand-in: the system reports $color; $reads counts the asks. */
function toolkitReporting(RenderedInstance $app, ?Color $color, int &$reads = 0): void
{
    $accent = new class($color, $reads) implements SystemAccent
    {
        public function __construct(private ?Color $color, private int &$reads) {}

        public function color(): ?Color
        {
            $this->reads++;

            return $this->color;
        }
    };
    $app->registerSingleton('toolkit-windows', fn () => new class($accent)
    {
        public function __construct(private SystemAccent $accent) {}

        public function systemAccent(): SystemAccent
        {
            return $this->accent;
        }
    });
}

function accentOf(RenderedInstance $app): string
{
    return $app->make('silk.looks')->current()->color(Role::Accent)->toHex();
}

it('merges the accent defaults', function () {
    $config = silkApp()->make('silk')->config();

    expect($config['accent'])->toBe('auto')
        ->and($config['accent_color'])->toBeNull();
});

it("takes the system accent under 'auto'", function () {
    $app = silkApp();
    toolkitReporting($app, Color::hex('#e62d42'));

    expect(accentOf($app))->toBe('#e62d42');
});

it("keeps the look's own accent under 'auto' when the system reports none, or no toolkit is bound", function () {
    $reporting = silkApp();
    toolkitReporting($reporting, null);

    expect(accentOf($reporting))->toBe('#3b82f6')
        ->and(accentOf(silkApp()))->toBe('#3b82f6');
});

it("takes the system accent under 'system', and refuses without one", function () {
    $app = silkApp(['accent' => 'system']);
    toolkitReporting($app, Color::hex('#9141ac'));
    expect(accentOf($app))->toBe('#9141ac');

    $none = silkApp(['accent' => 'system']);
    toolkitReporting($none, null);
    expect(fn () => $none->make('silk.looks'))->toThrow(RuntimeException::class, 'reports no system accent');

    expect(fn () => silkApp(['accent' => 'system'])->make('silk.looks'))->toThrow(RuntimeException::class, 'no toolkit-windows is bound');
});

it("keeps the look's own accent under 'theme' and never asks the system", function () {
    $reads = 0;
    $app = silkApp(['accent' => 'theme']);
    toolkitReporting($app, Color::hex('#e62d42'), $reads);

    expect(accentOf($app))->toBe('#3b82f6')
        ->and($reads)->toBe(0);
});

it("takes accent_color under 'custom', for every look", function () {
    $app = silkApp(['accent' => 'custom', 'accent_color' => '#2190A4', 'look' => 'raven-light']);
    toolkitReporting($app, Color::hex('#e62d42'));

    expect(accentOf($app))->toBe('#2190a4');
});

it("refuses a 'custom' accent without a valid colour, and an unknown mode", function (array $config, string $message) {
    expect(fn () => silkApp($config)->make('silk.looks'))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'missing' => [['accent' => 'custom'], 'silk.accent_color is NULL'],
    'not a string' => [['accent' => 'custom', 'accent_color' => 12], 'silk.accent_color is 12'],
    'not a colour' => [['accent' => 'custom', 'accent_color' => 'teal'], "'teal' is not #rgb"],
    'unknown mode' => [['accent' => 'sparkle'], "silk.accent is 'sparkle'; it is one of auto, system, theme, custom"],
]);

it('derives the roles that follow the accent', function () {
    $app = silkApp(['accent' => 'custom', 'accent_color' => '#3584E4']);
    $look = $app->make('silk.looks')->current();

    expect($look->color(Role::AccentHovered)->toHex())->toBe('#599ae9')
        ->and($look->color(Role::AccentPressed)->toHex())->toBe('#2b6cbb');
});
