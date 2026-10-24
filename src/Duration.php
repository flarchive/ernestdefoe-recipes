<?php

namespace ErnestDefoe\Recipes;

/**
 * A length of time written the short way: `25m`, `1h30m`, `1h 30m`, `90s`, or a
 * bare number, which means minutes.
 *
 * The letters are syntax, not prose — like the `[recipe]` tag itself they are
 * the same in every language, and what a reader SEES is built from the
 * forum's translations (see Render::duration()).
 */
final class Duration
{
    /** Twelve hours. A longer "timer" is a typo, and a huge number is a denial of patience. */
    public const MAX = 43200;

    public static function parse(string $text): ?int
    {
        $text = strtolower(trim($text));

        if ($text === '') {
            return null;
        }

        if (preg_match('/^\d{1,4}$/D', $text)) {
            return self::clamp((int) $text * 60);
        }

        if (! preg_match('/^(?:(\d{1,3})\s*h)?\s*(?:(\d{1,4})\s*m(?:in)?)?\s*(?:(\d{1,5})\s*s)?$/D', $text, $m)) {
            return null;
        }

        $seconds = (int) ($m[1] ?? 0) * 3600 + (int) ($m[2] ?? 0) * 60 + (int) ($m[3] ?? 0);

        return self::clamp($seconds);
    }

    /** ISO 8601, which is what schema.org's prepTime/cookTime/totalTime expect. */
    public static function iso(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return 'PT'.($h ? $h.'H' : '').($m ? $m.'M' : '').($s || (! $h && ! $m) ? $s.'S' : '');
    }

    private static function clamp(int $seconds): ?int
    {
        return $seconds > 0 && $seconds <= self::MAX ? $seconds : null;
    }
}
