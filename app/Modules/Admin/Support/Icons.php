<?php

namespace App\Modules\Admin\Support;

/**
 * Icônes de l'administration : la même police Ionicons que l'application
 * mobile (@expo/vector-icons), servie depuis public/admin-assets/fonts. La table
 * nom → glyphe est copiée de la glyphmap Ionicons (resources/data/ionicons.json).
 */
final class Icons
{
    /** @var array<string, int>|null */
    private static ?array $glyphs = null;

    public static function glyph(string $name): string
    {
        self::$glyphs ??= json_decode((string) file_get_contents(resource_path('data/ionicons.json')), true);

        $code = self::$glyphs[$name] ?? self::$glyphs['help-circle-outline'];

        return mb_chr($code, 'UTF-8');
    }
}
