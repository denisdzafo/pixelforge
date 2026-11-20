<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AdminReportRequest extends FormRequest
{
    public const int MAX_DAYS = 366;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start'],
        ];
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    public function range(): array
    {
        $end = $this->filled('end') ? CarbonImmutable::parse((string) $this->input('end'), 'UTC') : CarbonImmutable::now('UTC');
        $start = $this->filled('start') ? CarbonImmutable::parse((string) $this->input('start'), 'UTC') : $end->subDays(29);

        if ($start->diffInDays($end) >= self::MAX_DAYS) {
            $start = $end->subDays(self::MAX_DAYS - 1);
        }

        return [$start->startOfDay(), $end->startOfDay()];
    }
}
