<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Generation;
use App\Repositories\GenerationRepository;
use App\Repositories\UserRepository;
use App\Services\Pixelforge\Data\UsageDay;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AdminReportService
{
    private const int USAGE_MAX_DAYS = 92;

    public function __construct(
        private readonly PixelforgeClient $client,
        private readonly GenerationRepository $generations,
        private readonly UserRepository $users,
    ) {}

    /** @return array<string, mixed> */
    public function build(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = $this->generations->getSucceededCompletedBetween($start->startOfDay(), $end->endOfDay());

        $days = [];
        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $days[$day->toDateString()] = $this->bucket();
        }
        $users = [];
        $total = $this->bucket();

        foreach ($rows as $row) {
            $date = $row->pixelforge_completed_at?->utc()->toDateString();
            if ($date === null || ! isset($days[$date])) {
                continue;
            }
            $this->add($days[$date], $row);
            $this->add($total, $row);
            $users[$row->user_id ?? 0] ??= $this->bucket();
            $this->add($users[$row->user_id ?? 0], $row);
        }

        [$usage, $usageError] = $this->usage($start, $end);

        $dayRows = [];
        foreach ($days as $date => $bucket) {
            $provider = $usage[$date] ?? null;
            $dayRows[] = $this->present($bucket) + [
                'date' => $date,
                'pixelforge' => $provider === null ? null : $this->presentUsage($provider, $bucket),
            ];
        }

        $names = $this->users->getByIds(array_keys($users));
        $userRows = [];
        foreach ($users as $userId => $bucket) {
            $user = $names->get($userId);
            $userRows[] = $this->present($bucket) + [
                'user_id' => $userId ?: null,
                'name' => $user->name ?? 'Deleted user',
                'email' => $user?->email,
            ];
        }
        usort($userRows, fn ($a, $b) => (int) $b['charged_nanos'] <=> (int) $a['charged_nanos']);

        $providerTotal = $usage === null ? null : array_reduce(
            array_filter($usage, fn (UsageDay $d) => isset($days[(string) $d->date])),
            fn (int $sum, UsageDay $d) => $sum + $d->cost->nanos,
            0,
        );

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'days' => $dayRows,
            'users' => $userRows,
            'total' => $this->present($total) + [
                'pixelforge_cost_nanos' => $providerTotal === null ? null : (string) $providerTotal,
                'matches' => $providerTotal === null ? null : $providerTotal === $total['provider_cost_nanos'],
            ],
            'usage_error' => $usageError,
        ];
    }

    /** @return array{0: array<string, UsageDay>|null, 1: string|null} */
    private function usage(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $days = [];

        try {
            for ($chunkStart = $start; $chunkStart->lte($end); $chunkStart = $chunkStart->addDays(self::USAGE_MAX_DAYS)) {
                $chunkEnd = $chunkStart->addDays(self::USAGE_MAX_DAYS - 1)->min($end);
                $key = 'pixelforge_usage_'.$chunkStart->toDateString().'_'.$chunkEnd->toDateString();

                /** @var array<string, array<string, mixed>> $chunk */
                $chunk = Cache::remember($key, (int) config('pixelforge.cache.usage_seconds'), fn () => array_map(
                    fn (UsageDay $d) => [
                        'date' => $d->date,
                        'generations' => $d->generations,
                        ...$d->usage->toArray(),
                        'cost_usd' => $d->cost->toDecimal(),
                    ],
                    $this->client->getUsage($chunkStart, $chunkEnd),
                ));

                foreach ($chunk as $date => $row) {
                    $days[$date] = UsageDay::fromArray($row);
                }
            }
        } catch (PixelforgeException $e) {
            Log::warning('Could not load Pixelforge usage for the admin report', ['error' => $e->getMessage()]);

            return [null, 'Pixelforge usage could not be loaded right now, so reconciliation is unavailable.'];
        }

        return [$days, null];
    }

    /** @return array{generations: int, charged_nanos: int, provider_cost_nanos: int, tokens: array<string, int>} */
    private function bucket(): array
    {
        return [
            'generations' => 0,
            'charged_nanos' => 0,
            'provider_cost_nanos' => 0,
            'tokens' => ['input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0],
        ];
    }

    /** @param array{generations: int, charged_nanos: int, provider_cost_nanos: int, tokens: array<string, int>} $bucket */
    private function add(array &$bucket, Generation $row): void
    {
        $bucket['generations']++;
        $bucket['charged_nanos'] += (int) $row->charged_nanos;
        $bucket['provider_cost_nanos'] += (int) $row->provider_cost_nanos;
        foreach (array_keys($bucket['tokens']) as $type) {
            $bucket['tokens'][$type] += (int) $row->{$type};
        }
    }

    /**
     * @param  array{generations: int, charged_nanos: int, provider_cost_nanos: int, tokens: array<string, int>}  $bucket
     * @return array<string, mixed>
     */
    private function present(array $bucket): array
    {
        $profit = $bucket['charged_nanos'] - $bucket['provider_cost_nanos'];

        return [
            'generations' => $bucket['generations'],
            'charged_nanos' => (string) $bucket['charged_nanos'],
            'provider_cost_nanos' => (string) $bucket['provider_cost_nanos'],
            'profit_nanos' => (string) $profit,
            'margin_bps' => $bucket['charged_nanos'] === 0 ? null : intdiv($profit * 10_000, $bucket['charged_nanos']),
            'tokens' => $bucket['tokens'],
        ];
    }

    /**
     * @param  array{generations: int, charged_nanos: int, provider_cost_nanos: int, tokens: array<string, int>}  $ours
     * @return array<string, mixed>
     */
    private function presentUsage(UsageDay $day, array $ours): array
    {
        $difference = Money::nanos($ours['provider_cost_nanos'])->minus($day->cost);

        return [
            'generations' => $day->generations,
            'cost_nanos' => (string) $day->cost->nanos,
            'cost_usd' => $day->cost->toDecimal(),
            'tokens' => $day->usage->toArray(),
            'difference_nanos' => (string) $difference->nanos,
            'matches' => $difference->nanos === 0
                && $day->generations === $ours['generations']
                && $day->usage->toArray() === $ours['tokens'],
        ];
    }
}
