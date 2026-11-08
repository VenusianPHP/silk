<?php

namespace Silk\Looks;

/** Silk's built-in looks: the graph's tokens (tokens.php) over the shared Raven styles. */
final class BuiltInThemes
{
    /** config name => graph Theme name, in spec 7.5's look order */
    public const array NAMES = [
        'raven-dark' => 'RavenDark',
        'raven-light' => 'RavenLight',
        'raven-high-contrast-dark' => 'RavenHighContrastDark',
        'raven-high-contrast-light' => 'RavenHighContrastLight',
        'macos-dark' => 'MacOSDark',
        'macos-light' => 'MacOSLight',
        'gnome-dark' => 'GnomeDark',
        'gnome-light' => 'GnomeLight',
        'raven-glass-dark' => 'RavenGlassDark',
        'raven-glass-light' => 'RavenGlassLight',
        'macos-glass-dark' => 'MacOSGlassDark',
        'macos-glass-light' => 'MacOSGlassLight',
    ];

    /**
     * The system font of each platform family, first present wins (fontconfig); the app's font stays last, as
     * Raven falls back to its own (look.c:555-561). Raven looks keep the app's font (style.c:1005-1018).
     */
    private const array PLATFORM_FONTS = ['macos' => 'SF Pro Text,Helvetica Neue', 'gnome' => 'Adwaita Sans,Cantarell'];

    private static ?array $tokens = null;

    /** @return array<string, ?string> Raven's code token palette for a dark or a light theme (syntax.c:889-896), from the graph */
    public static function syntax(bool $dark): array
    {
        return (self::$tokens ??= require __DIR__.'/tokens.php')[$dark ? 'RavenDark' : 'RavenLight']['syntax'];
    }

    /** @return array<string, mixed> a theme array */
    public static function theme(string $graphName, string $family = 'sans-serif', string $mono = 'monospace'): array
    {
        $t = (self::$tokens ??= require __DIR__.'/tokens.php')[$graphName]
            ?? throw new \InvalidArgumentException("Silk has no built-in theme '{$graphName}'.");

        return [
            'look' => null,
            'colors' => $t['colors'],
            'metrics' => $t['metrics'],
            'font' => ['family' => isset(self::PLATFORM_FONTS[$t['family']]) ? self::PLATFORM_FONTS[$t['family']].','.$family : $family, 'mono' => $mono],
            'motion' => $t['motion'],
            'family' => $t['family'],
            'dark' => $t['dark'],
            // a Raven glass look is frosted glass; a platform's glass look stays solid and has its bars, menus, floating
            // surfaces and slider knobs drawn in glass by the widgets ('liquid', style.c:1000-1002)
            'material' => $t['glass'] && $t['family'] === 'raven' ? 'frosted' : 'solid',
            'liquid' => $t['glass'] && $t['family'] !== 'raven',
            'syntax' => $t['syntax'],
            // the family's rows come last: last written wins (spec 7.5)
            'styles' => [...RavenDark::styles(), ...match ($t['family']) {
                'macos' => Styles\MacOSStyles::list($t['dark']),
                'gnome' => Styles\GnomeStyles::list($t['dark']),
                default => [],
            }],
        ];
    }

    public static function register(LookManager $m, string $family, string $mono): LookManager
    {
        foreach (self::NAMES as $name => $graphName) {
            $m->extend($name, fn () => self::theme($graphName, $family, $mono));
        }

        return $m;
    }
}
