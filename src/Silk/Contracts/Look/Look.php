<?php

namespace Silk\Contracts\Look;

use Surface\NutsAndBolts\Color;

interface Look
{
    public function color(Role $r): Color;

    public function metric(Metric $m): float;

    /** The look's text style for a role, at Metric::FontSize. */
    public function font(FontRole $role = FontRole::Ui): FontSpec;

    /** Springy or smooth: how presses spring back. */
    public function motion(): Motion;

    /** @param list<string> $classes */
    public function part(string $widget, string $part, State $state, array $classes = []): PartLook;

    /**
     * A code token's colour, opaque, or null where Raven gives 0: paint those in the run's text colour (syntax.c:887-903).
     * A theme's own `syntax` entry first, else Raven's palette for a dark or a light theme.
     */
    public function syntax(SyntaxToken $t): ?Color;

    /**
     * The dark look of the same family, for widgets dark whatever the theme (Island): Raven rvn_theme_dark_twin
     * (theme.c:180-203). A dark look is its own twin; a light one keeps its accents, focus, metrics and font.
     */
    public function darkTwin(): Look;

    /** The look's family, 'raven', 'macos' or 'gnome' (Raven rvn_widget_family): what platform-shaped widgets follow. */
    public function family(): string;

    /** Whether the look is dark (Raven theme->dark). */
    public function dark(): bool;

    /**
     * What the look's surfaces are made of (Raven rvn_widget_material, widget.h:396-402): a platform family's looks are
     * always Solid, whatever the theme says.
     */
    public function material(): Material;

    /** A platform look whose bars, menus, floating surfaces and slider knobs the widgets draw in glass (Raven theme->liquid, macOS Glass). */
    public function liquid(): bool;
}
