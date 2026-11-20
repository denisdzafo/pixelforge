<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

class WebhookSignatureVerifier
{
    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSeconds = 300,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('pixelforge.webhook_secret'),
            (int) config('pixelforge.webhook_tolerance_seconds'),
        );
    }

    public function verify(string $payload, ?string $header, ?int $now = null): void
    {
        if ($this->secret === '') {
            throw new InvalidSignatureException('No webhook signing secret is configured');
        }

        if ($header === null || $header === '') {
            throw new InvalidSignatureException('Missing signature header');
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = strtolower($value);
            }
        }

        if ($timestamp === null || $signatures === []) {
            throw new InvalidSignatureException('Malformed signature header');
        }

        if (abs(($now ?? time()) - $timestamp) > $this->toleranceSeconds) {
            throw new InvalidSignatureException('Signature timestamp outside the tolerance');
        }

        $expected = $this->sign($payload, $timestamp);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return;
            }
        }

        throw new InvalidSignatureException('Signature mismatch');
    }

    public function sign(string $payload, int $timestamp): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);
    }
}
