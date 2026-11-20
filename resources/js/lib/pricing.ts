import { usdToNanos } from '@/lib/money';
import type { PricingCatalog, TokenUsage } from '@/types/generation';

const TOKENS_PER_RATE_UNIT = 1_000_000n;
const BPS = 10_000n;

export type Estimate = {
    min: bigint;
    expected: bigint;
    max: bigint;
    minUsage: TokenUsage;
    expectedUsage: TokenUsage;
    maxUsage: TokenUsage;
};

export function characterCount(text: string): number {
    return Array.from(text).length;
}

export function providerCost(
    usage: TokenUsage,
    rates: PricingCatalog['rates'],
): bigint {
    const total =
        BigInt(usage.input_text_tokens) * usdToNanos(rates.input_text_tokens) +
        BigInt(usage.input_image_tokens) *
            usdToNanos(rates.input_image_tokens) +
        BigInt(usage.output_tokens) * usdToNanos(rates.output_tokens) +
        BigInt(usage.reasoning_tokens) * usdToNanos(rates.reasoning_tokens);

    return total / TOKENS_PER_RATE_UNIT;
}

export function userCharge(cost: bigint, markupPercent: number): bigint {
    return (cost * BigInt(100 + markupPercent)) / 100n;
}

export function estimate(
    prompt: string,
    size: string,
    quality: string,
    referenceCount: number,
    catalog: PricingCatalog,
    markupPercent: number,
): Estimate | null {
    const output = catalog.output[quality]?.[size];
    const reasoning = catalog.reasoning[quality];
    if (output === undefined || reasoning === undefined) {
        return null;
    }

    const chars = BigInt(characterCount(prompt));
    const perToken = BigInt(catalog.characters_per_text_token);
    const variance = BigInt(catalog.text_token_variance_bps);
    const text = (chars + perToken - 1n) / perToken;
    const textMin = (text * (BPS - variance)) / BPS;
    const textMax = (text * (BPS + variance) + BPS - 1n) / BPS;
    const image = referenceCount * catalog.per_reference_image;

    const usage = (t: bigint, r: number): TokenUsage => ({
        input_text_tokens: Number(t),
        input_image_tokens: image,
        output_tokens: output,
        reasoning_tokens: r,
    });

    const minUsage = usage(textMin, reasoning.min);
    const expectedUsage = usage(
        text,
        Math.floor((reasoning.min + reasoning.max) / 2),
    );
    const maxUsage = usage(textMax, reasoning.max);
    const price = (u: TokenUsage) =>
        userCharge(providerCost(u, catalog.rates), markupPercent);

    return {
        min: price(minUsage),
        expected: price(expectedUsage),
        max: price(maxUsage),
        minUsage,
        expectedUsage,
        maxUsage,
    };
}
