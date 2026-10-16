<?php

namespace ErnestDefoe\Recipes;

/**
 * One recipe, read from the text inside a `[recipe]` block.
 *
 *     [recipe]
 *     title: Fluffy pancakes
 *     servings: 4
 *     prep: 10m
 *     cook: 20m
 *     image: https://example.com/pancakes.jpg
 *
 *     A weekend breakfast that takes less time than the coffee.
 *
 *     [ingredients]
 *     1 1/2 cups flour
 *     2 tbsp sugar
 *     For the topping:
 *     200 g blueberries
 *     [/ingredients]
 *
 *     [steps]
 *     Whisk the dry ingredients.
 *     Cook each side [2m] until golden.
 *     [/steps]
 *     [/recipe]
 *
 * Every editor stores this same text: the plain composer and FoF Rich Text as
 * written, Scribe as the content of an `<article>` element. That is why there
 * is ONE reader, here, and it runs at render time — a fix to it applies to
 * every recipe already posted, with nothing to re-parse or migrate.
 *
 * The keys (`title:`, `[steps]`) are syntax, the same in every language, like
 * a BBCode's name. Readers never see them; the composer's form writes them.
 */
final class Recipe
{
    public const MAX_ITEMS = 200;
    public const MAX_LINE = 500;
    public const MAX_TITLE = 200;
    public const MAX_SERVINGS = 999;

    /**
     * @param list<array{heading: string}|array{text: string, quantity: ?array<string, mixed>}> $ingredients
     * @param list<array{heading: string}|array{parts: list<array{text: string}|array{timer: int}>}> $steps
     */
    public function __construct(
        public readonly string $title,
        public readonly ?int $servings,
        public readonly ?int $prep,
        public readonly ?int $cook,
        public readonly ?string $image,
        public readonly string $description,
        public readonly array $ingredients,
        public readonly array $steps,
    ) {
    }

    public static function parse(string $body): self
    {
        $meta = ['title' => '', 'servings' => null, 'prep' => null, 'cook' => null, 'image' => null];
        $description = [];
        $ingredients = [];
        $steps = [];
        $state = 'head';
        $seen = false;

        $lines = preg_split('/\R/u', str_replace("\0", '', $body)) ?: [];

        foreach ($lines as $raw) {
            $line = trim($raw);

            if (preg_match('/^\[(\/?)(ingredients|steps)\]$/i', $line, $section)) {
                $state = $section[1] === '/' ? 'text' : strtolower($section[2]);
                continue;
            }

            if ($state === 'head') {
                // A blank line ends the header — once there is one to end.
                if ($line === '') {
                    if ($seen) {
                        $state = 'text';
                    }
                    continue;
                }

                if (preg_match('/^(title|servings|prep|cook|image)\s*:\s*(.*)$/i', $line, $kv)) {
                    $meta[strtolower($kv[1])] = trim($kv[2]);
                    $seen = true;
                    continue;
                }

                $state = 'text';
            }

            if ($line === '') {
                if ($state === 'text' && $description && end($description) !== '') {
                    $description[] = '';
                }
                continue;
            }

            $line = self::clip($line, self::MAX_LINE);

            if ($state === 'ingredients' && count($ingredients) < self::MAX_ITEMS) {
                $ingredients[] = self::ingredient($line);
            } elseif ($state === 'steps' && count($steps) < self::MAX_ITEMS) {
                $steps[] = self::step($line);
            } elseif ($state === 'text' && count($description) < self::MAX_ITEMS) {
                $description[] = $line;
            }
        }

        return new self(
            self::clip((string) $meta['title'], self::MAX_TITLE),
            self::servings((string) $meta['servings']),
            Duration::parse((string) $meta['prep']),
            Duration::parse((string) $meta['cook']),
            self::url((string) $meta['image']),
            trim(implode("\n", $description)),
            $ingredients,
            $steps,
        );
    }

    public function total(): ?int
    {
        return $this->prep === null && $this->cook === null ? null : (int) $this->prep + (int) $this->cook;
    }

    /** Nothing a reader could use: no title, no ingredients, no steps. */
    public function isEmpty(): bool
    {
        return $this->title === '' && ! $this->ingredients && ! $this->steps;
    }

    /** @return array{heading: string}|array{text: string, quantity: ?array<string, mixed>} */
    private static function ingredient(string $line): array
    {
        $line = self::unbullet($line, false);

        if ($heading = self::heading($line)) {
            return ['heading' => $heading];
        }

        return ['text' => $line, 'quantity' => Quantity::parse($line)];
    }

    /** @return array{heading: string}|array{parts: list<array{text: string}|array{timer: int}>} */
    private static function step(string $line): array
    {
        $line = self::unbullet($line, true);

        if ($heading = self::heading($line)) {
            return ['heading' => $heading];
        }

        $parts = [];
        $offset = 0;

        // `[25m]`, `[1h 30m]`: a timer, exactly where the author put it.
        if (preg_match_all('/\[([0-9hms ]{1,12})\]/i', $line, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => [$token, $at]) {
                $seconds = Duration::parse($matches[1][$i][0]);
                if ($seconds === null) {
                    continue;
                }

                if ($at > $offset) {
                    $parts[] = ['text' => substr($line, $offset, $at - $offset)];
                }
                $parts[] = ['timer' => $seconds];
                $offset = $at + strlen($token);
            }
        }

        if ($offset < strlen($line)) {
            $parts[] = ['text' => substr($line, $offset)];
        }

        return ['parts' => $parts];
    }

    /** Markdown habits are welcome in the lists; the bullet itself is ours to draw. */
    private static function unbullet(string $line, bool $numbers): string
    {
        $pattern = $numbers ? '/^(?:[-*•+]|\d{1,3}[.)])\s+/u' : '/^[-*•+]\s+/u';

        return (string) preg_replace($pattern, '', $line);
    }

    /** "For the sauce:" groups the lines below it. */
    private static function heading(string $line): ?string
    {
        if (str_ends_with($line, ':') && mb_strlen($line) <= 80 && Quantity::parse($line) === null) {
            $heading = trim(rtrim($line, ':'));

            return $heading === '' ? null : $heading;
        }

        return null;
    }

    private static function servings(string $text): ?int
    {
        if (! preg_match('/^\s*(\d{1,6})(?!\d)/', $text, $m)) {
            return null;
        }

        $n = (int) $m[1];

        return $n >= 1 && $n <= self::MAX_SERVINGS ? $n : null;
    }

    /**
     * 🚨 The only URL a recipe carries, so the only place one is checked: http
     * or https, nothing else. It ends up in an <img src> and in JSON-LD, and
     * `javascript:` in either is the obvious thing someone will try.
     */
    private static function url(string $text): ?string
    {
        $text = trim($text);

        if ($text === '' || strlen($text) > 2000 || ! preg_match('#^https?://[^\s"<>]+$#i', $text)) {
            return null;
        }

        return filter_var($text, FILTER_VALIDATE_URL) ? $text : null;
    }

    private static function clip(string $text, int $max): string
    {
        $text = trim($text);

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max)).'…' : $text;
    }
}
