<?php

declare(strict_types=1);

namespace AIForge\Campaign;

/**
 * The commit a plugin checkout sits on, read from .git without running git
 * (the containers have no git binary).
 */
final class PluginCommit
{
    public static function read(string $pluginDir): ?string
    {
        $git = rtrim($pluginDir, '/') . '/.git';
        $head = @file_get_contents("{$git}/HEAD");

        if ($head === false) {
            return null;
        }

        $head = trim($head);

        if (!str_starts_with($head, 'ref: ')) {
            return $head !== '' ? $head : null;
        }

        $ref = substr($head, 5);
        $loose = @file_get_contents("{$git}/{$ref}");

        if ($loose !== false) {
            return trim($loose);
        }

        foreach (@file("{$git}/packed-refs") ?: [] as $line) {
            $line = trim($line);

            if (str_ends_with($line, " {$ref}")) {
                return substr($line, 0, 40);
            }
        }

        return null;
    }
}
