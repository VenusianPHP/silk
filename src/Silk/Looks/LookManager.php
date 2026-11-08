<?php

namespace Silk\Looks;

use Closure;
use InvalidArgumentException;
use Silk\Contracts\Look\Look;
use Silk\Contracts\Look\Theme;
use Silk\Core\Tree\Tree;
use Surface\NutsAndBolts\Color;

/** `silk.looks`: themes join with extend(), the active one is picked with use(). */
class LookManager
{
    /** @var array<string, Closure> */
    private array $factories = [];

    private ?Look $current = null;

    private ?string $name = null;

    private ?Color $accent = null;

    /** @var list<Tree> */
    private array $trees = [];

    public function extend(string $name, Closure $factory): static
    {
        $this->factories[$name] = $factory;

        return $this;
    }

    /**
     * The accent every look takes in place of its own (null = each look keeps its own); the look in use is
     * rebuilt with it.
     */
    public function setAccent(?Color $accent): static
    {
        $this->accent = $accent;

        return $this->name === null ? $this : $this->use($this->name);
    }

    /** Resolves the theme, then hands it to every watched tree (Tree::setLook springs the colours). */
    public function use(string $name): static
    {
        $this->current = $this->build($name);
        $this->name = $name;
        foreach ($this->trees as $tree) {
            $tree->setLook($this->current);
        }

        return $this;
    }

    public function current(): Look
    {
        return $this->current ?? throw new InvalidArgumentException('No look is in use.');
    }

    public function watch(Tree $tree): static
    {
        $this->trees[] = $tree;

        return $this;
    }

    /** @param list<string> $seen guards against a theme that names itself as its base */
    private function build(string $name, array $seen = []): ThemedLook
    {
        $factory = $this->factories[$name] ?? throw new InvalidArgumentException("Silk has no look named '{$name}'.");
        if (in_array($name, $seen, true)) {
            throw new InvalidArgumentException("Look '{$name}' is its own base.");
        }
        $theme = $factory();
        $theme = $theme instanceof Theme ? $theme->toArray() : $theme;

        $base = isset($theme['look']) && $theme['look'] !== $name
            ? $this->build($theme['look'], [...$seen, $name])
            : new ThemedLook(new Palette, new Metrics, new StyleSheet);

        $palette = clone $base->palette;
        $palette->merge($theme['colors'] ?? []);
        $theme += $base->theme;
        // the one accent (the manager's, else the theme's own) gives the roles that follow it, as Raven does
        $accent = $this->accent ?? $theme['accent'] ?? null;
        if ($accent !== null) {
            $palette->merge(Accent::derive(Palette::parse($accent), (string) ($theme['family'] ?? 'raven'), (bool) ($theme['dark'] ?? true), $palette));
        }

        return new ThemedLook(
            $palette,
            (clone $base->metrics)->merge($theme['metrics'] ?? []),
            (clone $base->styles)->add($theme['styles'] ?? []),
            $theme,
        );
    }
}
