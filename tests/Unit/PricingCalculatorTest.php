<?php

use App\Services\Pixelforge\Data\PricingCatalog;
use App\Services\Pixelforge\Data\Rates;
use App\Services\Pixelforge\Data\Usage;
use App\Services\Pricing\PricingCalculator;
use App\Support\Money;

beforeEach(function () {
    $this->calculator = new PricingCalculator;
    $this->catalog = PricingCatalog::fromArray(pixelforgePricing());
    $this->rates = $this->catalog->rates;
});

it('matches the docs worked example exactly: $0.373875', function () {
    $cost = $this->calculator->providerCost(new Usage(115, 1530, 6450, 8000), $this->rates);

    expect($cost->nanos)->toBe(373_875_000)
        ->and($cost->toDecimal())->toBe('0.373875000');

    $estimate = $this->calculator->estimate(str_repeat('a', 400), '1536x1024', 'high', 2, $this->catalog);
    expect($estimate->maxUsage->toArray())->toBe([
        'input_text_tokens' => 115,
        'input_image_tokens' => 1530,
        'output_tokens' => 6450,
        'reasoning_tokens' => 8000,
    ])->and($estimate->max->nanos)->toBe(373_875_000 * 125 / 100);
});

it('matches the docs usage example: $0.169080', function () {
    expect($this->calculator->providerCost(new Usage(41, 0, 3300, 2950), $this->rates)->toDecimal())
        ->toBe('0.169080000');
});

it('adds exactly 25% markup', function () {
    expect($this->calculator->userCharge(Money::nanos(373_875_000))->nanos)->toBe(467_343_750)
        ->and($this->calculator->userCharge(Money::nanos(0))->nanos)->toBe(0)
        ->and($this->calculator->userCharge(Money::nanos(2_500))->nanos)->toBe(3_125);
});

it('rounds a non-whole markup down, in the user\'s favour', function () {
    expect($this->calculator->userCharge(Money::nanos(3))->nanos)->toBe(3);
});

it('charges whole nanos with an exact markup for every token count at today\'s rates', function () {
    foreach (Rates::TYPES as $i => $type) {
        foreach ([1, 7, 333, 8001] as $tokens) {
            $counts = [0, 0, 0, 0];
            $counts[$i] = $tokens;
            $cost = $this->calculator->providerCost(new Usage(...$counts), $this->rates);
            expect(($cost->nanos * 125) % 100)->toBe(0);
        }
    }
});

it('uses the generation\'s own rates', function () {
    $rates = Rates::fromArray([
        'input_text_tokens' => '1.00',
        'input_image_tokens' => '2.00',
        'output_tokens' => '3.00',
        'reasoning_tokens' => '4.125',
    ]);

    expect($this->calculator->providerCost(new Usage(10, 10, 10, 8), $rates)->nanos)->toBe(93_000);
});

it('prices every size and quality combination', function (string $quality, string $size, int $output) {
    $estimate = $this->calculator->estimate('abcd', $size, $quality, 0, $this->catalog);
    $reasoning = $this->catalog->reasoningRange($quality);

    expect($estimate->maxUsage->outputTokens)->toBe($output)
        ->and($estimate->maxUsage->reasoningTokens)->toBe($reasoning['max'])
        ->and($estimate->minUsage->reasoningTokens)->toBe($reasoning['min'])
        ->and($estimate->min->nanos)->toBeLessThanOrEqual($estimate->expected->nanos)
        ->and($estimate->expected->nanos)->toBeLessThanOrEqual($estimate->max->nanos);

    $maxProvider = (2 * 5_000 + $output * 40_000 + $reasoning['max'] * 12_500);
    expect($estimate->max->nanos)->toBe($maxProvider * 125 / 100);
})->with([
    ['low', '1024x1024', 280], ['low', '1536x1024', 420], ['low', '1024x1536', 420],
    ['medium', '1024x1024', 1100], ['medium', '1536x1024', 1650], ['medium', '1024x1536', 1650],
    ['high', '1024x1024', 4300], ['high', '1536x1024', 6450], ['high', '1024x1536', 6450],
]);

it('keeps every possible actual cost inside the estimate range', function (int $chars, string $quality) {
    $prompt = str_repeat('x', $chars);
    $estimate = $this->calculator->estimate($prompt, '1536x1024', $quality, 3, $this->catalog);
    $expectedText = (int) ceil($chars / 4);
    $reasoning = $this->catalog->reasoningRange($quality);

    foreach ([(int) floor($expectedText * 0.85), $expectedText, (int) ceil($expectedText * 1.15)] as $text) {
        foreach ([$reasoning['min'], $reasoning['max']] as $r) {
            $actual = $this->calculator->userCharge(
                $this->calculator->providerCost(new Usage($text, 3 * 765, $this->catalog->outputTokens($quality, '1536x1024'), $r), $this->rates),
            );
            expect($actual->nanos)->toBeGreaterThanOrEqual($estimate->min->nanos)
                ->toBeLessThanOrEqual($estimate->max->nanos);
        }
    }
})->with([1, 3, 4, 5, 39, 400, 1001, 4000])->with(['low', 'medium', 'high']);

it('counts unicode characters, not bytes', function () {
    $estimate = $this->calculator->estimate('ééééé', '1024x1024', 'low', 0, $this->catalog);
    expect($estimate->expectedUsage->inputTextTokens)->toBe(2);
});

it('parses the pricing variance without floats', function () {
    expect($this->catalog->textTokenVarianceBps)->toBe(1500);
});
