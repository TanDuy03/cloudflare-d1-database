<?php

declare(strict_types=1);

use Ntanduy\CFD1\Console\Concerns\FormatsBytes;

/*
|--------------------------------------------------------------------------
| FormatsBytes trait – full branch coverage
|--------------------------------------------------------------------------
*/

// Create a concrete class that uses the trait so we can test it
class FormatsBytesTestClass
{
    use FormatsBytes;

    public function format(int $bytes): string
    {
        return $this->formatBytes($bytes);
    }
}

test('formatBytes returns bytes for values under 1000', function () {
    $formatter = new FormatsBytesTestClass;

    expect($formatter->format(0))->toBe('0 B');
    expect($formatter->format(1))->toBe('1 B');
    expect($formatter->format(999))->toBe('999 B');
});

test('formatBytes returns kB for values 1000-999999', function () {
    $formatter = new FormatsBytesTestClass;

    expect($formatter->format(1000))->toBe('1 kB');
    expect($formatter->format(1500))->toBe('1.5 kB');
    expect($formatter->format(999999))->toBe('1000 kB');
});

test('formatBytes returns MB for values 1000000-999999999', function () {
    $formatter = new FormatsBytesTestClass;

    expect($formatter->format(1_000_000))->toBe('1 MB');
    expect($formatter->format(2_500_000))->toBe('2.5 MB');
    expect($formatter->format(999_999_999))->toBe('1000 MB');
});

test('formatBytes returns GB for values >= 1000000000', function () {
    $formatter = new FormatsBytesTestClass;

    expect($formatter->format(1_000_000_000))->toBe('1 GB');
    expect($formatter->format(2_500_000_000))->toBe('2.5 GB');
    expect($formatter->format(10_000_000_000))->toBe('10 GB');
});
