<?php

declare(strict_types=1);

namespace App\Services\Pixelforge;

use App\Services\Pixelforge\Data\GenerationData;
use App\Services\Pixelforge\Data\PricingCatalog;
use App\Services\Pixelforge\Data\UsageDay;
use App\Services\Pixelforge\Exceptions\ConcurrencyLimitException;
use App\Services\Pixelforge\Exceptions\ConnectionFailedException;
use App\Services\Pixelforge\Exceptions\ContentPolicyViolationException;
use App\Services\Pixelforge\Exceptions\DailyLimitExceededException;
use App\Services\Pixelforge\Exceptions\IdempotencyKeyReusedException;
use App\Services\Pixelforge\Exceptions\InvalidApiKeyException;
use App\Services\Pixelforge\Exceptions\NotFoundException;
use App\Services\Pixelforge\Exceptions\OverloadedException;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\Exceptions\RateLimitedException;
use App\Services\Pixelforge\Exceptions\ServerErrorException;
use App\Services\Pixelforge\Exceptions\ValidationFailedException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

class PixelforgeClient
{
    public const string BUDGET_KEY = 'pixelforge-api-requests';

    /** @param array{prompt: string, size: string, quality: string, reference_images?: list<string>, metadata?: array<string, string>} $params */
    public function createGeneration(array $params, string $idempotencyKey): GenerationData
    {
        $response = $this->send(fn (PendingRequest $http) => $http
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('generations', $params));

        if ($response->header('Idempotent-Replayed') === 'true') {
            Log::info('Pixelforge replayed an idempotent create', ['idempotency_key' => $idempotencyKey]);
        }

        return $this->generation($response);
    }

    public function getGeneration(string $pixelforgeId): GenerationData
    {
        return $this->generation($this->send(fn (PendingRequest $http) => $http->get('generations/'.rawurlencode($pixelforgeId))));
    }

    public function getPricing(): PricingCatalog
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('pricing'), budgeted: false);

        return $this->parse(fn () => PricingCatalog::fromArray($this->json($response)));
    }

    /** @return array<string, UsageDay> */
    public function getUsage(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('usage', [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
        ]));

        return $this->parse(function () use ($response) {
            $days = [];
            foreach ((array) ($this->json($response)['data'] ?? []) as $row) {
                $day = UsageDay::fromArray((array) $row);
                if ($day->date !== null) {
                    $days[$day->date] = $day;
                }
            }

            return $days;
        });
    }

    /** @param callable(PendingRequest): Response $request */
    private function send(callable $request, bool $budgeted = true): Response
    {
        if ($budgeted) {
            $this->takeFromBudget();
        }

        $http = Http::baseUrl(rtrim((string) config('pixelforge.base_url'), '/'))
            ->withToken((string) config('pixelforge.api_key'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('pixelforge.http.timeout'))
            ->connectTimeout((int) config('pixelforge.http.connect_timeout'));

        try {
            $response = $request($http);
        } catch (ConnectionException $e) {
            Log::warning('Pixelforge could not be reached', ['error' => $e->getMessage()]);

            throw new ConnectionFailedException('Pixelforge could not be reached', previous: $e);
        }

        if ($response->successful()) {
            return $response;
        }

        throw $this->toException($response);
    }

    private function takeFromBudget(): void
    {
        $max = (int) config('pixelforge.limits.requests_per_minute');

        if (RateLimiter::tooManyAttempts(self::BUDGET_KEY, $max)) {
            $wait = max(1, RateLimiter::availableIn(self::BUDGET_KEY));

            throw new RateLimitedException('Local Pixelforge request budget used up', 'local_budget', 429, $wait);
        }

        RateLimiter::hit(self::BUDGET_KEY, 60);
    }

    private function toException(Response $response): PixelforgeException
    {
        $status = $response->status();
        $body = $response->json();
        $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];
        $code = is_string($error['code'] ?? null) ? $error['code'] : null;
        $message = is_string($error['message'] ?? null) ? $error['message'] : "Pixelforge returned HTTP {$status}";
        $retryAfter = $this->retryAfter($response);

        Log::warning('Pixelforge request failed', ['status' => $status, 'code' => $code, 'message' => $message]);

        return match (true) {
            $code === 'concurrency_limit_exceeded' => new ConcurrencyLimitException($message, $code, $status, $retryAfter ?? 5),
            $code === 'daily_limit_exceeded' => new DailyLimitExceededException($message, $code, $status, $retryAfter),
            $code === 'rate_limit_exceeded', $status === 429 => new RateLimitedException($message, $code, $status, $retryAfter ?? 10),
            $code === 'overloaded', $status === 503 => new OverloadedException($message, $code, $status, $retryAfter ?? 2),
            $code === 'content_policy_violation' => new ContentPolicyViolationException($message, $code, $status),
            $code === 'idempotency_key_reused', $status === 409 => new IdempotencyKeyReusedException($message, $code, $status),
            $code === 'invalid_api_key', $status === 401 => new InvalidApiKeyException($message, $code, $status),
            $status === 404 => new NotFoundException($message, $code, $status),
            $status === 422, $status === 400 => new ValidationFailedException($message, $code, $status),
            $status >= 500 => new ServerErrorException($message, $code, $status, $retryAfter),
            default => new PixelforgeException($message, $code, $status),
        };
    }

    private function retryAfter(Response $response): ?int
    {
        $value = $response->header('Retry-After');

        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    private function generation(Response $response): GenerationData
    {
        return $this->parse(fn () => GenerationData::fromArray($this->json($response)));
    }

    /** @return array<mixed> */
    private function json(Response $response): array
    {
        $json = $response->json();

        return is_array($json) ? $json : throw new InvalidArgumentException('Pixelforge did not return JSON');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $parse
     * @return T
     */
    private function parse(callable $parse): mixed
    {
        try {
            return $parse();
        } catch (InvalidArgumentException $e) {
            Log::error('Unexpected Pixelforge response', ['error' => $e->getMessage()]);

            throw new ServerErrorException('Unexpected Pixelforge response: '.$e->getMessage(), previous: $e);
        }
    }
}
