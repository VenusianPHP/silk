<?php

namespace Silk\Looks\Canvas;

/**
 * The looks the NodeGraph and the Timeline start from (Raven RVisualPreset, canvas_look.c:15-36): Auto is the
 * look of the window's platform family; Blueprint, Blender and Neon are looks of the night whatever the theme.
 */
enum CanvasPreset: string
{
    case Auto = 'auto';
    case Modern = 'modern';
    case Blueprint = 'blueprint';
    case Blender = 'blender';
    case Neon = 'neon';

    /** By name, without case, with Raven's other words (rvn_canvas_preset_by_name). */
    public static function byName(string $name): ?self
    {
        foreach (self::cases() as $preset) {
            if (strcasecmp($preset->name, $name) === 0) {
                return $preset;
            }
        }

        return match (strtolower($name)) {
            'default', 'theme', 'native' => self::Auto,
            'clean', 'minimal' => self::Modern,
            'unreal' => self::Blueprint,
            'dark', 'glow' => self::Neon,
            default => null,
        };
    }

    /** Always dark (canvas_look.c:79-80). */
    public function night(): bool
    {
        return in_array($this, [self::Blueprint, self::Blender, self::Neon], true);
    }
}
