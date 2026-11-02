<?php

namespace ErnestDefoe\Recipes\Tests\unit;

use ErnestDefoe\Recipes\Quantity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QuantityTest extends TestCase
{
    /** @return array<string, array{0: string, 1: float, 2: ?float, 3: ?string, 4: bool, 5: string}> */
    public static function amounts(): array
    {
        return [
            'whole' => ['2 eggs', 2, null, null, false, ' eggs'],
            'mixed fraction' => ['1 1/2 cups flour', 1.5, null, 'cups', false, ' flour'],
            'plain fraction' => ['3/4 cup milk', 0.75, null, 'cup', false, ' milk'],
            'vulgar' => ['½ tsp salt', 0.5, null, 'tsp', false, ' salt'],
            'whole + vulgar' => ['1½ cups sugar', 1.5, null, 'cups', false, ' sugar'],
            'spaced vulgar' => ['2 ¼ cups oats', 2.25, null, 'cups', false, ' oats'],
            'decimal' => ['1.5 kg potatoes', 1.5, null, 'kg', true, ' potatoes'],
            'comma decimal' => ['0,5 l Milch', 0.5, null, 'l', true, ' Milch'],
            'unit glued on' => ['200g butter', 200, null, 'g', true, ' butter'],
            'range' => ['2-3 cloves garlic', 2, 3, 'cloves', false, ' garlic'],
            'en-dash range' => ['2–3 tbsp oil', 2, 3, 'tbsp', false, ' oil'],
            'unit with a dot' => ['2 tbsp. butter', 2, null, 'tbsp.', false, ' butter'],
            'two-word unit' => ['4 fl oz cream', 4, null, 'fl oz', false, ' cream'],
            'capitalised unit' => ['2 Cups rice', 2, null, 'Cups', false, ' rice'],
            'no unit, word starts like one' => ['1 large onion', 1, null, null, false, ' large onion'],
            'no unit, "l" prefix' => ['3 lemons', 3, null, null, false, ' lemons'],
        ];
    }

    #[DataProvider('amounts')]
    public function test_reads_the_amount(string $line, float $q, ?float $q2, ?string $unit, bool $decimal, string $rest): void
    {
        $parsed = Quantity::parse($line);

        $this->assertNotNull($parsed);
        $this->assertEqualsWithDelta($q, $parsed['q'], 0.0001);
        $this->assertSame($q2 === null ? null : (float) $q2, $parsed['q2']);
        $this->assertSame($unit, $parsed['unit']);
        $this->assertSame($decimal, $parsed['decimal']);
        $this->assertSame($rest, $parsed['rest']);
    }

    /** @return array<string, array{0: string}> */
    public static function notAmounts(): array
    {
        return [
            'no number' => ['salt to taste'],
            'zero' => ['0 eggs'],
            'backwards range' => ['3-1 apples'],
            'divide by zero' => ['1/0 cup'],
            'number later in the line' => ['Eggs, 2'],
            'empty' => [''],
        ];
    }

    #[DataProvider('notAmounts')]
    public function test_leaves_lines_without_an_amount_alone(string $line): void
    {
        $this->assertNull(Quantity::parse($line));
    }

    public function test_unit_words_carry_both_forms(): void
    {
        $parsed = Quantity::parse('1 cup flour');

        $this->assertSame(['cup', 'cups'], [$parsed['one'] ?? null, $parsed['many'] ?? null]);
        $this->assertNull(Quantity::parse('2 tbsp oil')['one'] ?? null, 'abbreviations do not change');
    }

    public function test_gap_between_amount_and_unit_is_kept(): void
    {
        $this->assertSame('', Quantity::parse('200g butter')['gap'] ?? null);
        $this->assertSame(' ', Quantity::parse('200 g butter')['gap'] ?? null);
    }
}
