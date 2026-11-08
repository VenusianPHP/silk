<?php

namespace Silk\Hosts;

use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Host\Host as HostContract;
use Silk\Contracts\Input\SilkEvent;
use Silk\Core\Input\FocusManager;
use Silk\Core\Input\Router;
use Silk\Core\Tree\Tree;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Fonts\GFXFont;

/** What a Silk tree lives in: an engine, a size in points, a pixel scale, and an event queue. */
abstract class Host implements HostContract
{
    protected Router $router;

    protected ?RenderingEngine $engine = null;

    protected float $pointsW = 0.0;

    protected float $pointsH = 0.0;

    protected float $pixelScale = 1.0;

    /** @var list<SilkEvent> */
    private array $queue = [];

    public function __construct(
        protected readonly Tree $tree,
        protected readonly FrameLoop $frames = new FrameLoop,
        protected float $uiScale = 1.0,
        public ?GFXFont $font = null,
        ?bool $reducedMotion = null,
    ) {
        $tree->reducedMotion = $reducedMotion ?? self::configured('silk.reduced_motion', false);
        $this->router = new Router($tree, new FocusManager($tree));
        $tree->setTranslator(self::bound('silk.translator'));
    }

    /** A container binding when an app is booted and has it, else null. */
    protected static function bound(string $key): mixed
    {
        try {
            return function_exists('app') && app()->has($key) ? app($key) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** A config value when an app is booted, else $default. */
    protected static function configured(string $key, mixed $default): mixed
    {
        try {
            return function_exists('app') && app()->has('config') ? app('config')->get($key, $default) : $default;
        } catch (\Throwable) {
            return $default; // no app booted (unit tests, scripts)
        }
    }

    /** Shows what was painted, if the engine does not present on its own. */
    abstract public function present(): void;

    public function tree(): Tree
    {
        return $this->tree;
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function frame(): void
    {
        $this->frames->frame($this);
    }

    public function engine(): ?RenderingEngine
    {
        return $this->engine;
    }

    /** Device pixels per logical unit: window (pixel) scale x UI scale. */
    public function scale(): float
    {
        return $this->pixelScale * $this->uiScale;
    }

    /** The logical size the tree is laid out to. */
    public function viewport(): Size
    {
        return new Size($this->pointsW / $this->uiScale, $this->pointsH / $this->uiScale);
    }

    /** Engine, size in points and pixel scale; a null engine or empty size pauses the host. */
    public function bind(?RenderingEngine $engine, float $pointsW, float $pointsH, float $pixelScale): void
    {
        if ($engine !== $this->engine) {
            $this->tree->repaintAll();
        }
        $this->engine = $engine;
        [$this->pointsW, $this->pointsH] = [max(0.0, $pointsW), max(0.0, $pointsH)];
        $this->setPixelScale($pixelScale);
    }

    /** Springs keep their values; the next frame repaints at the new scale. */
    public function setPixelScale(float $scale): void
    {
        if ($scale > 0 && $scale !== $this->pixelScale) {
            $this->pixelScale = $scale;
            $this->tree->repaintAll();
        }
    }

    /**
     * Mail for widgets rather than for the host's view, such as a ClipboardText answering a paste:
     * offered to each widget with a handles(object) (TextInput) until one takes it.
     */
    protected function offerToWidgets(object $mail): bool
    {
        $walk = function ($w) use (&$walk, $mail): bool {
            if (method_exists($w, 'handles') && $w->handles($mail)) {
                return true;
            }
            foreach ($w instanceof \Silk\Contracts\Widget\Container ? $w->children() : [] as $child) {
                if ($walk($child)) {
                    return true;
                }
            }

            return false;
        };
        foreach ([$this->tree->root, ...$this->tree->overlays()->all()] as $root) {
            if ($walk($root)) {
                return true;
            }
        }

        return false;
    }

    public function enqueue(SilkEvent $e): void
    {
        $this->queue[] = $e;
    }

    /** @return list<SilkEvent> */
    public function drain(): array
    {
        [$events, $this->queue] = [$this->queue, []];

        return $events;
    }
}
