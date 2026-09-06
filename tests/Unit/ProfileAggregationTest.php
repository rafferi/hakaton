<?php

use App\Services\AI\ProfileAggregationService;

function coveredMonthsOf(string $from, string $to): int
{
    $method = new ReflectionMethod(ProfileAggregationService::class, 'coveredMonths');

    return $method->invoke(new ProfileAggregationService(), $from, $to);
}

test('covered months counts inclusive calendar months', function () {
    // 2026-05-01..2026-09-23 → май, июнь, июль, август, сентябрь = 5.
    expect(coveredMonthsOf('2026-05-01', '2026-09-23'))->toBe(5);
});

test('covered months is at least one for short ranges', function () {
    expect(coveredMonthsOf('2026-09-01', '2026-09-01'))->toBe(1);
    expect(coveredMonthsOf('2026-09-01', '2026-09-07'))->toBe(1);
    expect(coveredMonthsOf('2026-08-15', '2026-09-15'))->toBe(2);
});

test('covered months falls back to one on garbage input', function () {
    expect(coveredMonthsOf('not-a-date', 'also-bad'))->toBe(1);
});
