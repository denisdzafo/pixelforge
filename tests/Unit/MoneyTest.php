<?php

use App\Support\Money;

it('parses decimal USD strings exactly', function (string $usd, int $nanos) {
    expect(Money::fromUsd($usd)->nanos)->toBe($nanos);
})->with([
    ['0', 0],
    ['1', 1_000_000_000],
    ['12.50', 12_500_000_000],
    ['0.373875', 373_875_000],
    ['0.373875000', 373_875_000],
    ['0.000000001', 1],
    ['-2.5', -2_500_000_000],
    ['0.1000000000000', 100_000_000],
]);

it('rejects values it cannot represent exactly', function (string $usd) {
    Money::fromUsd($usd);
})->with(['0.0000000001', 'abc', '1e5', '', '1.2.3', '9999999999999.0'])->throws(InvalidArgumentException::class);

it('formats exactly, trimming zeros', function (int $nanos, string $decimal, string $formatted) {
    $money = Money::nanos($nanos);
    expect($money->toDecimal())->toBe($decimal)
        ->and($money->format())->toBe($formatted);
})->with([
    [373_875_000, '0.373875000', '$0.373875'],
    [0, '0.000000000', '$0.00'],
    [5_000_000_000, '5.000000000', '$5.00'],
    [1, '0.000000001', '$0.000000001'],
    [-46_125_000, '-0.046125000', '-$0.046125'],
    [1_234_500_000_000, '1234.500000000', '$1,234.50'],
]);

it('serializes as a string so JavaScript keeps every digit', function () {
    expect(json_encode(['a' => Money::nanos(9_007_199_254_740_993)]))->toBe('{"a":"9007199254740993"}');
});

it('parses decimals at other scales', function () {
    expect(Money::parseDecimal('0.15', 4))->toBe(1500)
        ->and(Money::parseDecimal('1', 4))->toBe(10_000);
});
