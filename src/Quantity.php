<?php

namespace ErnestDefoe\Recipes;

/**
 * Reads the amount at the start of an ingredient line, so it can be scaled.
 *
 *   "1 1/2 cups flour"   → 1.5, unit "cups"
 *   "1½ cups flour"      → 1.5, unit "cups"
 *   "200g butter"        → 200, unit "g", shown as a decimal when scaled
 *   "2–3 cloves garlic"  → a range, 2 to 3
 *   "salt to taste"      → nothing to scale; the line is shown as written
 *
 * Only the NUMBER is ever rewritten in the browser. The rest of the line —
 * the unit included — stays exactly as the author typed it, apart from the
 * singular/plural swap for the unit words listed below.
 *
 * 🚨 This is the only parser. The browser does not re-read ingredient text;
 * it multiplies what this class wrote into the rendered HTML. Two parsers
 * would disagree eventually, and the disagreement would show up as a recipe
 * that scales differently in the post than it reads in search results.
 */
final class Quantity
{
    private const VULGAR = [
        '½' => 0.5, '⅓' => 1 / 3, '⅔' => 2 / 3, '¼' => 0.25, '¾' => 0.75,
        '⅕' => 0.2, '⅖' => 0.4, '⅗' => 0.6, '⅘' => 0.8, '⅙' => 1 / 6,
        '⅚' => 5 / 6, '⅛' => 0.125, '⅜' => 0.375, '⅝' => 0.625, '⅞' => 0.875,
    ];

    /**
     * Units we recognise. Metric ones scale as decimals ("1.5 kg"), the rest as
     * kitchen fractions ("1½ cups").
     *
     * Words carry [singular, plural] so "1 cup" doubled reads "2 cups". The
     * forms are English because they are matched against what the author wrote;
     * a unit this list does not know is left alone, never mangled.
     *
     * @var array<string, array{0: bool, 1?: string, 2?: string}> lowercase spelling => [metric, one, many]
     */
    private const UNITS = [
        'g' => [true], 'gr' => [true], 'gram' => [true, 'gram', 'grams'], 'grams' => [true, 'gram', 'grams'],
        'kg' => [true], 'kilogram' => [true, 'kilogram', 'kilograms'], 'kilograms' => [true, 'kilogram', 'kilograms'],
        'mg' => [true], 'ml' => [true], 'cl' => [true], 'dl' => [true], 'l' => [true],
        'litre' => [true, 'litre', 'litres'], 'litres' => [true, 'litre', 'litres'],
        'liter' => [true, 'liter', 'liters'], 'liters' => [true, 'liter', 'liters'],
        'millilitre' => [true, 'millilitre', 'millilitres'], 'millilitres' => [true, 'millilitre', 'millilitres'],
        'milliliter' => [true, 'milliliter', 'milliliters'], 'milliliters' => [true, 'milliliter', 'milliliters'],
        'tsp' => [false], 'tbsp' => [false], 'tbs' => [false], 'oz' => [false], 'fl oz' => [false],
        'lb' => [false], 'lbs' => [false], 'pt' => [false], 'qt' => [false], 'gal' => [false],
        'teaspoon' => [false, 'teaspoon', 'teaspoons'], 'teaspoons' => [false, 'teaspoon', 'teaspoons'],
        'tablespoon' => [false, 'tablespoon', 'tablespoons'], 'tablespoons' => [false, 'tablespoon', 'tablespoons'],
        'cup' => [false, 'cup', 'cups'], 'cups' => [false, 'cup', 'cups'],
        'ounce' => [false, 'ounce', 'ounces'], 'ounces' => [false, 'ounce', 'ounces'],
        'pound' => [false, 'pound', 'pounds'], 'pounds' => [false, 'pound', 'pounds'],
        'pint' => [false, 'pint', 'pints'], 'pints' => [false, 'pint', 'pints'],
        'quart' => [false, 'quart', 'quarts'], 'quarts' => [false, 'quart', 'quarts'],
        'gallon' => [false, 'gallon', 'gallons'], 'gallons' => [false, 'gallon', 'gallons'],
        'pinch' => [false, 'pinch', 'pinches'], 'pinches' => [false, 'pinch', 'pinches'],
        'dash' => [false, 'dash', 'dashes'], 'dashes' => [false, 'dash', 'dashes'],
        'clove' => [false, 'clove', 'cloves'], 'cloves' => [false, 'clove', 'cloves'],
        'can' => [false, 'can', 'cans'], 'cans' => [false, 'can', 'cans'],
        'stick' => [false, 'stick', 'sticks'], 'sticks' => [false, 'stick', 'sticks'],
        'slice' => [false, 'slice', 'slices'], 'slices' => [false, 'slice', 'slices'],
        'sprig' => [false, 'sprig', 'sprigs'], 'sprigs' => [false, 'sprig', 'sprigs'],
        'handful' => [false, 'handful', 'handfuls'], 'handfuls' => [false, 'handful', 'handfuls'],
        'bunch' => [false, 'bunch', 'bunches'], 'bunches' => [false, 'bunch', 'bunches'],
    ];

    /** A single amount: "1 1/2", "1½", "1/2", "½", "1.5", "1,5", "2". */
    private const NUMBER = '(?:\d{1,4}\s+\d{1,3}\s*\/\s*\d{1,3}|\d{1,4}\s*[½⅓⅔¼¾⅕⅖⅗⅘⅙⅚⅛⅜⅝⅞]|\d{1,3}\s*\/\s*\d{1,3}|[½⅓⅔¼¾⅕⅖⅗⅘⅙⅚⅛⅜⅝⅞]|\d{1,5}(?:[.,]\d{1,3})?)';

    private static ?string $unitPattern = null;

    /**
     * @return array{q: float, q2: ?float, qty: string, gap: string, unit: ?string, one: ?string, many: ?string, decimal: bool, rest: string}|null
     */
    public static function parse(string $line): ?array
    {
        if (! preg_match('/^('.self::NUMBER.')(?:\s*[-–—]\s*('.self::NUMBER.'))?(?![\d\/])/u', $line, $m)) {
            return null;
        }

        $q = self::value($m[1]);
        $q2 = isset($m[2]) ? self::value($m[2]) : null;

        // "0 eggs" and "3–1" are not amounts anyone means; show the line as written.
        if ($q === null || $q <= 0 || ($q2 !== null && $q2 <= $q)) {
            return null;
        }

        $qty = $m[0];
        $rest = (string) substr($line, strlen($qty));
        $unit = null;
        $gap = '';
        $known = null;

        if (preg_match('/^(\s*)((?:'.self::unitPattern().')\.?)(?![\p{L}\d])/iu', $rest, $u)) {
            $gap = $u[1] === '' ? '' : ' ';
            $unit = $u[2];
            $known = self::UNITS[strtolower((string) preg_replace('/\s+/', ' ', rtrim($unit, '.')))] ?? null;
            $rest = (string) substr($rest, strlen($u[0]));
        }

        // A decimal written by the author stays a decimal; so does anything metric.
        $decimal = ($known[0] ?? false) || preg_match('/\d[.,]\d/', $qty) === 1;

        return [
            'q' => $q,
            'q2' => $q2,
            'qty' => trim($qty),
            'gap' => $gap,
            'unit' => $unit,
            'one' => $known[1] ?? null,
            'many' => $known[2] ?? null,
            'decimal' => $decimal,
            'rest' => $rest,
        ];
    }

    private static function value(string $text): ?float
    {
        $text = trim($text);

        foreach (self::VULGAR as $glyph => $fraction) {
            if (str_ends_with($text, $glyph)) {
                $whole = trim(substr($text, 0, -strlen($glyph)));

                return ($whole === '' ? 0 : (int) $whole) + $fraction;
            }
        }

        if (preg_match('/^(?:(\d+)\s+)?(\d+)\s*\/\s*(\d+)$/', $text, $f)) {
            if ((int) $f[3] === 0) {
                return null;
            }

            return (int) $f[1] + (int) $f[2] / (int) $f[3];
        }

        return (float) str_replace(',', '.', $text);
    }

    private static function unitPattern(): string
    {
        if (self::$unitPattern === null) {
            $units = array_keys(self::UNITS);
            // Longest first, so "tablespoons" is not read as "tablespoon" + "s".
            usort($units, fn ($a, $b) => strlen($b) - strlen($a));
            self::$unitPattern = implode('|', array_map(fn ($u) => str_replace(' ', '\s+', preg_quote($u, '/')), $units));
        }

        return self::$unitPattern;
    }
}
