<?php

namespace Silk\Core\Providers;

use Silk\Core\Silk;
use Silk\Hosts\FramePump;
use Silk\Looks\AccentSource;
use Silk\Looks\LookManager;
use Silk\Looks\BuiltInThemes;
use Voyager\NutsAndBolts\AggregateServiceProvider;

class SilkServiceProvider extends AggregateServiceProvider
{
    protected array $providers = [];

    private function configFile(): string
    {
        return dirname(__DIR__, 4).'/config/silk.php';
    }

    public function register(): void
    {
        $this->mergeConfigFrom($this->configFile(), 'silk');

        $this->app->registerSingleton('silk', fn ($app) => new Silk($app->make('config')->get('silk', [])));
        $this->app->alias('silk', Silk::class);

        $this->app->registerSingleton('silk.looks', fn ($app) => BuiltInThemes::register(
            new LookManager,
            $app->make('config')->get('silk.font.family', 'sans-serif'),
            $app->make('config')->get('silk.font.mono', 'monospace'),
        )
            ->setAccent(AccentSource::resolve(
                $app->make('config')->get('silk.accent', 'auto'),
                $app->make('config')->get('silk.accent_color'),
                fn () => $app->has('toolkit-windows') ? $app->make('toolkit-windows')->systemAccent() : null,
            ))
            ->use($app->make('config')->get('silk.look', 'raven-dark')));
        $this->app->alias('silk.looks', LookManager::class);
        // the words widgets show (month and day names...); bind another to translate
        $this->app->registerSingleton('silk.translator', fn () => new \Silk\Text\EnglishTranslator);
        $this->app->alias('silk.translator', \Silk\Contracts\Text\Translator::class);

        $this->callAfterResolving('event-loop', fn ($loop) => FramePump::join($loop, $this->app->make('silk')));

        parent::register();
    }

    public function boot(): void
    {
        $this->publishes([$this->configFile() => $this->app->configPath('silk.php')], 'silk-config');
    }
}
