<?php

namespace ErnestDefoe\Recipes\Tests\unit;

use ErnestDefoe\Recipes\Duration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DurationTest extends TestCase
{
    /** @return array<string, array{0: string, 1: ?int}> */
    public static function durations(): array
    {
        return [
            'minutes' => ['25m', 1500],
            'min' => ['25 min', 1500],
            'bare number is minutes' => ['15', 900],
            'hours and minutes' => ['1h30m', 5400],
            'spaced' => ['1h 30m', 5400],
            'seconds' => ['90s', 90],
            'all three' => ['1h 2m 3s', 3723],
            'upper case' => ['2H', 7200],
            'zero' => ['0m', null],
            'over twelve hours' => ['13h', null],
            'words' => ['twenty minutes', null],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('durations')]
    public function test_parses(string $text, ?int $seconds): void
    {
        $this->assertSame($seconds, Duration::parse($text));
    }

    public function test_iso_8601(): void
    {
        $this->assertSame('PT25M', Duration::iso(1500));
        $this->assertSame('PT1H30M', Duration::iso(5400));
        $this->assertSame('PT1H2M3S', Duration::iso(3723));
        $this->assertSame('PT2H', Duration::iso(7200));
    }
}
