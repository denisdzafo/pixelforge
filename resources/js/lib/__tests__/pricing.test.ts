import { describe, expect, it } from 'vite-plus/test';
import {
    formatApprox,
    formatRange,
    formatSigned,
    formatUsd,
    usdToNanos,
} from '@/lib/money';
import {
    characterCount,
    estimate,
    providerCost,
    userCharge,
} from '@/lib/pricing';
import type { PricingCatalog } from '@/types/generation';

const catalog: PricingCatalog = {
    rates: {
        input_text_tokens: '5.00',
        input_image_tokens: '10.00',
        output_tokens: '40.00',
        reasoning_tokens: '12.50',
    },
    characters_per_text_token: 4,
    text_token_variance_bps: 1500,
    per_reference_image: 765,
    output: {
        low: { '1024x1024': 280, '1536x1024': 420, '1024x1536': 420 },
        medium: { '1024x1024': 1100, '1536x1024': 1650, '1024x1536': 1650 },
        high: { '1024x1024': 4300, '1536x1024': 6450, '1024x1536': 6450 },
    },
    reasoning: {
        low: { min: 0, max: 500 },
        medium: { min: 200, max: 2000 },
        high: { min: 1000, max: 8000 },
    },
};

describe('pricing (mirror of PricingCalculator.php)', () => {
    it('matches the docs worked example: $0.373875', () => {
        const cost = providerCost(
            {
                input_text_tokens: 115,
                input_image_tokens: 1530,
                output_tokens: 6450,
                reasoning_tokens: 8000,
            },
            catalog.rates,
        );
        expect(cost).toBe(373_875_000n);
        expect(formatUsd(cost)).toBe('$0.373875');
    });

    it('estimates the same maximum as the server', () => {
        const result = estimate(
            'a'.repeat(400),
            '1536x1024',
            'high',
            2,
            catalog,
            25,
        )!;
        expect(result.maxUsage.input_text_tokens).toBe(115);
        expect(result.max).toBe(467_343_750n);
        expect(estimate('x', '1536x1024', 'high', 0, catalog, 25)!.max).toBe(
            447_512_500n,
        );
    });

    it('adds the markup exactly, rounding down', () => {
        expect(userCharge(373_875_000n, 25)).toBe(467_343_750n);
        expect(userCharge(3n, 25)).toBe(3n);
    });

    it('counts code points like mb_strlen', () => {
        expect(characterCount('ééééé')).toBe(5);
        expect(characterCount('🙂🙂')).toBe(2);
    });
});

describe('money formatting', () => {
    it('parses and formats exactly', () => {
        expect(usdToNanos('12.50')).toBe(12_500_000_000n);
        expect(formatUsd('1')).toBe('$0.000000001');
        expect(formatUsd('5000000000')).toBe('$5.00');
        expect(formatUsd('1234500000000')).toBe('$1,234.50');
        expect(formatSigned('-70700000')).toBe('−$0.0707');
    });

    it('rounds ranges outwards and approximations half-up', () => {
        expect(formatRange('47812500', '132343750')).toBe('$0.0478 – $0.1324');
        expect(formatApprox('70700000')).toBe('≈ $0.07');
        expect(formatApprox('1000')).toBe('< $0.01');
    });

    it('never loses precision beyond 2^53', () => {
        expect(formatUsd('9007199254740993')).toBe('$9,007,199.254740993');
    });
});
