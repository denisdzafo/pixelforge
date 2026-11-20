const NANOS_PER_USD = 1_000_000_000n;
const SCALE = 9;

export type Nanos = string | bigint;

export function toNanos(value: Nanos | null | undefined): bigint {
    if (value === null || value === undefined || value === '') {
        return 0n;
    }

    return typeof value === 'bigint' ? value : BigInt(value);
}

export function usdToNanos(usd: string): bigint {
    const match = /^(-)?(\d+)(?:\.(\d*))?$/.exec(usd.trim());
    if (!match) {
        throw new Error(`Not a decimal number: ${usd}`);
    }

    const fraction = (match[3] ?? '').padEnd(SCALE, '0');
    if (fraction.length > SCALE && /[1-9]/.test(fraction.slice(SCALE))) {
        throw new Error(`More than ${SCALE} decimals: ${usd}`);
    }

    const nanos =
        BigInt(match[2]) * NANOS_PER_USD + BigInt(fraction.slice(0, SCALE));

    return match[1] ? -nanos : nanos;
}

function groupThousands(whole: bigint): string {
    return whole.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

export function formatUsd(value: Nanos): string {
    const nanos = toNanos(value);
    const negative = nanos < 0n;
    const abs = negative ? -nanos : nanos;
    const whole = abs / NANOS_PER_USD;
    let fraction = (abs % NANOS_PER_USD)
        .toString()
        .padStart(SCALE, '0')
        .replace(/0+$/, '');
    fraction = fraction.padEnd(2, '0');

    return `${negative ? '-' : ''}$${groupThousands(whole)}.${fraction}`;
}

export function formatUsdRounded(
    value: Nanos,
    decimals = 2,
    mode: 'half-up' | 'down' | 'up' = 'half-up',
): string {
    const nanos = toNanos(value);
    const negative = nanos < 0n;
    const abs = negative ? -nanos : nanos;
    const step = 10n ** BigInt(SCALE - decimals);
    let units = abs / step;
    const remainder = abs % step;

    if (
        (mode === 'half-up' && remainder * 2n >= step) ||
        (mode === 'up' && remainder > 0n)
    ) {
        units += 1n;
    }

    const divisor = 10n ** BigInt(decimals);
    const whole = units / divisor;
    const fraction =
        decimals > 0
            ? '.' + (units % divisor).toString().padStart(decimals, '0')
            : '';

    return `${negative && units > 0n ? '-' : ''}$${groupThousands(whole)}${fraction}`;
}

export function formatApprox(value: Nanos): string {
    const nanos = toNanos(value);
    if (nanos > 0n && nanos < 5_000_000n) {
        return '< $0.01';
    }

    return `≈ ${formatUsdRounded(nanos, 2)}`;
}

export function formatRange(min: Nanos, max: Nanos): string {
    return `${formatUsdRounded(min, 4, 'down')} – ${formatUsdRounded(max, 4, 'up')}`;
}

export function formatSigned(value: Nanos): string {
    const nanos = toNanos(value);
    if (nanos < 0n) {
        return `−${formatUsd(-nanos)}`;
    }

    return `+${formatUsd(nanos)}`;
}

export function formatBps(bps: number | null): string {
    if (bps === null) {
        return '—';
    }

    const sign = bps < 0 ? '-' : '';
    const abs = Math.abs(bps);

    return `${sign}${Math.trunc(abs / 100)}.${String(abs % 100).padStart(2, '0')}%`;
}
