<?php

use App\Services\Webhooks\InvalidSignatureException;
use App\Services\Webhooks\WebhookSignatureVerifier;

beforeEach(function () {
    $this->verifier = new WebhookSignatureVerifier('whsec_test', 300);
    $this->body = '{"id":"evt_1","type":"ping"}';
    $this->now = 1_762_265_009;
    $this->header = fn (string $body, int $t, string $secret = 'whsec_test') => "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", $secret);
});

it('accepts a valid signature', function () {
    $this->verifier->verify($this->body, ($this->header)($this->body, $this->now), $this->now);
    expect(true)->toBeTrue();
});

it('accepts any of several v1 signatures', function () {
    $valid = ($this->header)($this->body, $this->now);
    $header = "t={$this->now},v1=".str_repeat('0', 64).','.explode(',', $valid)[1];

    $this->verifier->verify($this->body, $header, $this->now);
    expect(true)->toBeTrue();
});

it('rejects bad signatures', function (Closure $make) {
    [$body, $header, $now] = $make->call($this);
    $this->verifier->verify($body, $header, $now);
})->with([
    'wrong secret' => fn () => [$this->body, ($this->header)($this->body, $this->now, 'whsec_other'), $this->now],
    'tampered body' => fn () => [str_replace('ping', 'pong', $this->body), ($this->header)($this->body, $this->now), $this->now],
    'expired timestamp' => fn () => [$this->body, ($this->header)($this->body, $this->now - 301), $this->now],
    'future timestamp' => fn () => [$this->body, ($this->header)($this->body, $this->now + 301), $this->now],
    'missing header' => fn () => [$this->body, null, $this->now],
    'malformed header' => fn () => [$this->body, 'v1=abc', $this->now],
    'garbage header' => fn () => [$this->body, 'nonsense', $this->now],
    'non-numeric timestamp' => fn () => [$this->body, 't=abc,v1=00', $this->now],
])->throws(InvalidSignatureException::class);

it('refuses everything without a configured secret', function () {
    (new WebhookSignatureVerifier(''))->verify($this->body, ($this->header)($this->body, $this->now, ''), $this->now);
})->throws(InvalidSignatureException::class);
