<?php

namespace ErnestDefoe\Recipes;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * The extra fields an admin has defined for this forum's recipes: protein,
 * calories, PHE, whatever the community tracks.
 *
 * Stored as one JSON setting and validated HERE, on every read, rather than
 * when it is saved: Flarum's settings endpoint takes any string from an admin,
 * and a value that reached the database some other way (an import, a hand
 * edit) must not be able to break a recipe card. Anything malformed is
 * dropped, not repaired into something nobody asked for.
 *
 * Values in a recipe are numbers only. Free text would be a second
 * description with none of its limits, and could not scale with servings.
 */
class Fields
{
    public const SETTING = 'ernestdefoe-recipes.fields';
    public const MAX = 20;

    /** Header keys the recipe text already uses; a field may not shadow one. */
    public const RESERVED = ['title', 'servings', 'prep', 'cook', 'image', 'based-on'];

    /** The schema.org NutritionInformation properties a field may feed. */
    public const SCHEMA = [
        'calories' => 'calories',
        'carbohydrate' => 'carbohydrateContent',
        'cholesterol' => 'cholesterolContent',
        'fat' => 'fatContent',
        'fiber' => 'fiberContent',
        'protein' => 'proteinContent',
        'saturatedFat' => 'saturatedFatContent',
        'sodium' => 'sodiumContent',
        'sugar' => 'sugarContent',
        'transFat' => 'transFatContent',
        'unsaturatedFat' => 'unsaturatedFatContent',
    ];

    /** @var list<array{key: string, label: string, unit: string, scope: string, schema: ?string}>|null */
    private ?array $fields = null;

    public function __construct(private readonly SettingsRepositoryInterface $settings)
    {
    }

    /** @return list<array{key: string, label: string, unit: string, scope: string, schema: ?string}> */
    public function all(): array
    {
        return $this->fields ??= self::normalize((string) $this->settings->get(self::SETTING));
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_column($this->all(), 'key');
    }

    /** @return list<array{key: string, label: string, unit: string, scope: string, schema: ?string}> */
    public static function normalize(string $json): array
    {
        $raw = json_decode($json, true);
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        $seen = [];

        foreach ($raw as $field) {
            if (! is_array($field) || count($out) >= self::MAX) {
                continue;
            }

            $key = strtolower(trim((string) ($field['key'] ?? '')));
            $label = self::text($field['label'] ?? '', 40);

            if (! preg_match('/^[a-z][a-z0-9-]{0,29}$/D', $key) || in_array($key, self::RESERVED, true) || isset($seen[$key]) || $label === '') {
                continue;
            }

            $schema = $field['schema'] ?? null;
            $seen[$key] = true;
            $out[] = [
                'key' => $key,
                'label' => $label,
                'unit' => self::text($field['unit'] ?? '', 12),
                // Per serving stays put when the servings change; for the whole recipe scales.
                'scope' => ($field['scope'] ?? '') === 'recipe' ? 'recipe' : 'serving',
                'schema' => is_string($schema) && isset(self::SCHEMA[$schema]) ? $schema : null,
            ];
        }

        return $out;
    }

    /**
     * "12", "12.5", "12,5" → 12.5. Anything else, a negative or an absurd
     * value is not a value.
     */
    public static function number(string $text): ?float
    {
        $text = trim($text);

        if (! preg_match('/^\d{1,7}(?:[.,]\d{1,3})?$/D', $text)) {
            return null;
        }

        return (float) str_replace(',', '.', $text);
    }

    /** 12.50 → "12.5", 3.0 → "3". */
    public static function display(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    private static function text(mixed $value, int $max): string
    {
        $text = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', is_string($value) ? $value : '') ?? '');

        return mb_substr($text, 0, $max);
    }
}
