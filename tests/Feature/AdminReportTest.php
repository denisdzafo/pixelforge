<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['pixelforge.base_url' => 'https://pf.test/v1', 'pixelforge.api_key' => 'sk_test']);
    Http::preventStrayRequests();
    $this->admin = User::factory()->admin()->create();
    $this->alice = User::factory()->create(['name' => 'Alice']);
    $this->bob = User::factory()->create(['name' => 'Bob']);

    $make = fn (User $user, string $completedAt, int $cost, string $status = 'succeeded') => Generation::factory()->for($user)->create([
        'status' => GenerationStatus::from($status),
        'provider_cost_nanos' => $cost,
        'charged_nanos' => $status === 'succeeded' ? $cost * 125 / 100 : 0,
        'input_text_tokens' => 10, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 200,
        'pixelforge_completed_at' => $completedAt,
    ]);
    $make($this->alice, '2026-11-01 23:59:59', 46_550_000);
    $make($this->bob, '2026-11-02 00:00:00', 46_550_000);
    $make($this->alice, '2026-11-02 10:00:00', 46_550_000);
    $make($this->alice, '2026-11-02 11:00:00', 0, 'failed');
});

function usageDay(string $date, int $generations, string $cost): array
{
    return [
        'date' => $date, 'generations' => $generations,
        'input_text_tokens' => 10 * $generations, 'input_image_tokens' => 0,
        'output_tokens' => 1100 * $generations, 'reasoning_tokens' => 200 * $generations, 'cost_usd' => $cost,
    ];
}

it('totals spend, provider cost and profit per UTC day and per user, reconciled with Pixelforge', function () {
    Http::fake(['pf.test/v1/usage*' => Http::response(['object' => 'usage', 'data' => [
        usageDay('2026-11-01', 1, '0.046550000'),
        usageDay('2026-11-02', 2, '0.093100001'),
    ], 'total' => []])]);

    $this->actingAs($this->admin)->get(route('admin.report', ['start' => '2026-11-01', 'end' => '2026-11-02']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Report')
            ->where('report.days.0.date', '2026-11-01')
            ->where('report.days.0.generations', 1)
            ->where('report.days.0.charged_nanos', '58187500')
            ->where('report.days.0.provider_cost_nanos', '46550000')
            ->where('report.days.0.profit_nanos', '11637500')
            ->where('report.days.0.margin_bps', 2000)
            ->where('report.days.0.pixelforge.matches', true)
            ->where('report.days.1.generations', 2)
            ->where('report.days.1.provider_cost_nanos', '93100000')
            ->where('report.days.1.pixelforge.matches', false)
            ->where('report.days.1.pixelforge.difference_nanos', '-1')
            ->where('report.total.generations', 3)
            ->where('report.total.charged_nanos', '174562500')
            ->where('report.total.provider_cost_nanos', '139650000')
            ->where('report.total.pixelforge_cost_nanos', '139650001')
            ->where('report.total.matches', false)
            ->where('report.users.0.name', 'Alice')
            ->where('report.users.0.generations', 2)
            ->where('report.users.1.name', 'Bob')
            ->where('report.users.1.charged_nanos', '58187500'));
});

it('splits long ranges into 92-day usage calls', function () {
    Http::fake(['pf.test/v1/usage*' => Http::response(['object' => 'usage', 'data' => [], 'total' => []])]);

    $this->actingAs($this->admin)->get(route('admin.report', ['start' => '2026-01-01', 'end' => '2026-12-31']))->assertOk();

    $ranges = collect(Http::recorded())->map(fn ($pair) => [$pair[0]['start_date'], $pair[0]['end_date']])->all();
    expect($ranges)->toBe([
        ['2026-01-01', '2026-04-02'],
        ['2026-04-03', '2026-07-03'],
        ['2026-07-04', '2026-10-03'],
        ['2026-10-04', '2026-12-31'],
    ]);
});

it('still shows our numbers when Pixelforge usage is unavailable', function () {
    Http::fake(['*' => Http::response('down', 503)]);

    $this->actingAs($this->admin)->get(route('admin.report', ['start' => '2026-11-01', 'end' => '2026-11-02']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.total.provider_cost_nanos', '139650000')
            ->where('report.days.0.pixelforge', null)
            ->whereType('report.usage_error', 'string'));
});

it('caches the usage response briefly', function () {
    Http::fake(['pf.test/v1/usage*' => Http::response(['object' => 'usage', 'data' => [], 'total' => []])]);

    $this->actingAs($this->admin)->get(route('admin.report', ['start' => '2026-11-01', 'end' => '2026-11-02']));
    $this->actingAs($this->admin)->get(route('admin.report', ['start' => '2026-11-01', 'end' => '2026-11-02']));

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer sk_test'));
});
