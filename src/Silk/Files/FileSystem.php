<?php

namespace Silk\Files;

/**
 * The file system as the file browser needs it: folders, paths, the usual places of the user (Raven
 * core/fs.c, its POSIX side). Paths are absolute, '/'-separated.
 */
final class FileSystem
{
    /**
     * Lists a folder, without "." and "..", in no particular order; null when it cannot be read
     * (rvn_dir_read, fs.c:377-407). A link is followed: a link to a folder is a folder.
     *
     * @return list<array{name: string, isDir: bool, hidden: bool, size: int, modified: int}>|null
     */
    public static function read(string $path): ?array
    {
        $names = is_dir($path) && is_readable($path) ? @scandir($path, SCANDIR_SORT_NONE) : false;
        if ($names === false) {
            return null;
        }
        $entries = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full = self::child($path, $name);
            // (a link that leads nowhere is listed as a file of no size, as Raven does when stat fails)
            $info = file_exists($full) ? stat($full) : false;
            $entries[] = [
                'name' => $name,
                'isDir' => $info !== false && ($info['mode'] & 0170000) === 0040000,
                'hidden' => $name[0] === '.',
                'size' => $info === false ? 0 : (int) $info['size'],
                'modified' => $info === false ? 0 : (int) $info['mtime'],
            ];
        }

        return $entries;
    }

    /** fs.c:42-50 */
    public static function child(string $folder, string $name): string
    {
        return $folder === '' || str_ends_with($folder, '/') ? $folder.$name : $folder.'/'.$name;
    }

    /** The folder holding a path, or null at the root (rvn_path_parent, fs.c:76-85). */
    public static function parent(string $path): ?string
    {
        $root = str_starts_with($path, '/') ? 1 : 0;
        $length = strlen($path);
        while ($length > $root && $path[$length - 1] === '/') {
            $length--;
        }
        if ($length <= $root) {
            return null;
        }
        while ($length > $root && $path[$length - 1] !== '/') {
            $length--;
        }
        while ($length > $root && $path[$length - 1] === '/') {
            $length--;
        }

        return $length === 0 ? null : substr($path, 0, $length);
    }

    public static function name(string $path): string
    {
        $trimmed = rtrim($path, '/');

        return $trimmed === '' ? '/' : basename($trimmed);
    }

    /** "~/x" from the home, relative from the working folder, "." and ".." resolved (rvn_path_absolute, fs.c:431-448). */
    public static function absolute(?string $path): ?string
    {
        $path = $path === null || $path === '' ? '.' : $path;
        if ($path[0] === '~' && (strlen($path) === 1 || $path[1] === '/')) {
            $path = self::child(self::home(), substr($path, 2));
        } elseif ($path[0] !== '/') {
            $current = getcwd();
            if ($current === false) {
                return null;
            }
            $path = self::child($current, $path);
        }
        // rvn_path_clean (fs.c:88-123)
        $out = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            $part === '..' ? array_pop($out) : $out[] = $part;
        }

        return '/'.implode('/', $out);
    }

    public static function home(): string
    {
        $home = getenv('HOME');

        return $home !== false && $home !== '' ? $home : '/';
    }

    public static function isDir(string $path): bool
    {
        return is_dir($path);
    }

    public static function exists(string $path): bool
    {
        return file_exists($path);
    }

    /**
     * Does a name match "*.png;*.jpg" (or "*", "*.*", an exact name, without case)? No patterns: yes
     * (rvn_pattern_match, fs.c:125-150).
     */
    public static function matches(?string $patterns, string $name): bool
    {
        if ($patterns === null || $patterns === '') {
            return true;
        }
        foreach (preg_split('/[;, ]+/', $patterns, -1, PREG_SPLIT_NO_EMPTY) as $pattern) {
            if ($pattern === '*' || $pattern === '*.*') {
                return true;
            }
            if (strlen($pattern) >= 2 && $pattern[0] === '*') {
                $suffix = substr($pattern, 1);
                if (strlen($name) >= strlen($suffix) && strcasecmp(substr($name, -strlen($suffix)), $suffix) === 0) {
                    return true;
                }
            } elseif (strcasecmp($pattern, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The usual places: Home, Desktop, Documents, Downloads, Pictures (those that exist), Computer
     * (rvn_fs_places, fs.c:498-508); each path once.
     *
     * @return list<array{label: string, path: string}>
     */
    public static function places(): array
    {
        $home = self::home();
        $places = [['label' => 'Home', 'path' => $home]];
        foreach (['Desktop', 'Documents', 'Downloads', 'Pictures'] as $name) {
            is_dir(self::child($home, $name)) && $places[] = ['label' => $name, 'path' => self::child($home, $name)];
        }
        $places[] = ['label' => 'Computer', 'path' => '/'];
        $seen = [];

        return array_values(array_filter($places, function ($p) use (&$seen) {
            return ! isset($seen[$p['path']]) && $seen[$p['path']] = true;
        }));
    }
}
