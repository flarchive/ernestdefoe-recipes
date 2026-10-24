<?php

namespace ErnestDefoe\Recipes\Tests\unit;

use ErnestDefoe\Recipes\Fields;
use PHPUnit\Framework\TestCase;

class FieldsTest extends TestCase
{
    public function test_keeps_valid_fields_in_order(): void
    {
        $fields = Fields::normalize(json_encode([
            ['key' => 'protein', 'label' => 'Protein', 'unit' => 'g', 'scope' => 'serving', 'schema' => 'protein'],
            ['key' => 'phe', 'label' => 'PHE', 'unit' => 'mg', 'scope' => 'recipe'],
        ]));

        $this->assertSame([
            ['key' => 'protein', 'label' => 'Protein', 'unit' => 'g', 'scope' => 'serving', 'schema' => 'protein'],
            ['key' => 'phe', 'label' => 'PHE', 'unit' => 'mg', 'scope' => 'recipe', 'schema' => null],
        ], $fields);
    }

    public function test_drops_what_it_cannot_trust(): void
    {
        $fields = Fields::normalize(json_encode([
            ['key' => 'title', 'label' => 'Shadows a built-in'],
            ['key' => 'Bad Key!', 'label' => 'Spaces'],
            ['key' => '1st', 'label' => 'Starts with a digit'],
            ['key' => 'ok', 'label' => ''],
            ['key' => 'fat', 'label' => 'Fat', 'schema' => 'notAProperty', 'scope' => 'whatever'],
            ['key' => 'fat', 'label' => 'Duplicate'],
            'not an object',
            ['key' => 'kcal', 'label' => "Cal\u{0}ories\nnow", 'unit' => str_repeat('u', 40)],
        ]));

        $this->assertSame(['fat', 'kcal'], array_column($fields, 'key'));
        $this->assertNull($fields[0]['schema'], 'unknown schema property dropped');
        $this->assertSame('serving', $fields[0]['scope'], 'unknown scope means per serving');
        $this->assertSame('Cal ories now', $fields[1]['label'], 'control characters removed');
        $this->assertSame(12, mb_strlen($fields[1]['unit']));
    }

    public function test_caps_the_number_of_fields(): void
    {
        $many = array_map(fn ($i) => ['key' => "f$i", 'label' => "F$i"], range(1, 30));

        $this->assertCount(Fields::MAX, Fields::normalize(json_encode($many)));
    }

    public function test_garbage_is_no_fields(): void
    {
        foreach (['', 'null', '{"a":1}', 'not json', '"string"'] as $json) {
            $this->assertSame([], Fields::normalize($json), $json);
        }
    }

    public function test_numbers(): void
    {
        $this->assertSame(12.0, Fields::number('12'));
        $this->assertSame(12.5, Fields::number('12.5'));
        $this->assertSame(12.5, Fields::number(' 12,5 '));
        foreach (['-1', 'abc', '1e9', '12.3456', '', '12 g', '99999999'] as $bad) {
            $this->assertNull(Fields::number($bad), $bad);
        }
        $this->assertSame('12.5', Fields::display(12.50));
        $this->assertSame('3', Fields::display(3.0));
    }
}
