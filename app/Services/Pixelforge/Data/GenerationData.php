<?php

declare(strict_types=1);

namespace App\Services\Pixelforge\Data;

use App\Enums\GenerationStatus;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class GenerationData
{
    /**
     * @param  list<string>  $referenceImages
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $id,
        public GenerationStatus $status,
        public string $prompt,
        public string $size,
        public string $quality,
        public array $referenceImages,
        public array $metadata,
        public ?string $imageUrl,
        public ?CarbonImmutable $imageUrlExpiresAt,
        public ?Usage $usage,
        public Rates $rates,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?CarbonImmutable $createdAt,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $completedAt,
    ) {}

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? null;
        $status = GenerationStatus::tryFrom(is_string($data['status'] ?? null) ? $data['status'] : '');

        if (! is_string($id) || $id === '' || $status === null || $status === GenerationStatus::Pending) {
            throw new InvalidArgumentException('Not a Pixelforge generation object');
        }

        $error = is_array($data['error'] ?? null) ? $data['error'] : null;
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];

        return new self(
            id: $id,
            status: $status,
            prompt: self::string($data, 'prompt') ?? '',
            size: self::string($data, 'size') ?? '',
            quality: self::string($data, 'quality') ?? '',
            referenceImages: array_values(array_filter((array) ($data['reference_images'] ?? []), 'is_string')),
            metadata: array_filter($metadata, fn ($v, $k) => is_string($k) && is_string($v), ARRAY_FILTER_USE_BOTH),
            imageUrl: self::string($data, 'image_url'),
            imageUrlExpiresAt: self::date($data, 'image_url_expires_at'),
            usage: is_array($data['usage'] ?? null) ? Usage::fromArray($data['usage']) : null,
            rates: Rates::fromArray(is_array($data['rates'] ?? null) ? $data['rates'] : []),
            errorCode: $error !== null && is_string($error['code'] ?? null) ? $error['code'] : null,
            errorMessage: $error !== null && is_string($error['message'] ?? null) ? $error['message'] : null,
            createdAt: self::date($data, 'created_at'),
            startedAt: self::date($data, 'started_at'),
            completedAt: self::date($data, 'completed_at'),
        );
    }

    public function localId(): ?string
    {
        return $this->metadata['generation_id'] ?? null;
    }

    /** @param array<mixed> $data */
    private static function string(array $data, string $key): ?string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : null;
    }

    /** @param array<mixed> $data */
    private static function date(array $data, string $key): ?CarbonImmutable
    {
        $value = self::string($data, $key);

        return $value === null ? null : CarbonImmutable::parse($value)->utc();
    }
}
