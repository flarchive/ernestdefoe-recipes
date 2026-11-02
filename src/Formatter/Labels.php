<?php

namespace ErnestDefoe\Recipes\Formatter;

use Flarum\Locale\TranslatorInterface;

/**
 * The words on a recipe card, in the reader's language.
 *
 * Resolved per render rather than baked into the compiled templates, so a
 * forum with several languages shows each reader their own.
 */
class Labels
{
    /**
     * 🚨 What a reader sees when no language pack carries our strings — a forum
     * without flarum/lang-english, or one whose only pack predates us. The
     * translator answers a missing key with the key itself, and
     * "ernestdefoe-recipes.forum.cook" is not a word.
     */
    private const ENGLISH = [
        'servings' => 'Serves', 'prep' => 'Prep', 'cook' => 'Cook', 'total' => 'Total',
        'ingredients' => 'Ingredients', 'steps' => 'Method', 'based_on' => 'Based on another recipe', 'per_serving' => 'per serving',
        'duration.hours' => '{count} h', 'duration.minutes' => '{count} min', 'duration.seconds' => '{count} s',
    ];

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function get(string $key): string
    {
        return $this->trans($key);
    }

    /** "1 h 30 min". */
    public function duration(int $seconds): string
    {
        $parts = [];
        foreach (['hours' => intdiv($seconds, 3600), 'minutes' => intdiv($seconds % 3600, 60), 'seconds' => $seconds % 60] as $unit => $count) {
            if ($count > 0) {
                $parts[] = $this->trans('duration.'.$unit, ['count' => $count]);
            }
        }

        return implode(' ', $parts);
    }

    /** @param array<string, int> $parameters */
    private function trans(string $key, array $parameters = []): string
    {
        $id = 'ernestdefoe-recipes.forum.'.$key;
        $text = (string) $this->translator->trans($id, $parameters);

        if ($text === $id || $text === '') {
            $text = str_replace('{count}', (string) ($parameters['count'] ?? ''), self::ENGLISH[$key] ?? $key);
        }

        return $text;
    }
}
