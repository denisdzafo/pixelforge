<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(pixelforgePricing())]);
});

it('requires sign-in', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with(['generations.create', 'generations.index', 'credits.index', 'admin.report']);

it('only lists the user\'s own generations', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    Generation::factory()->for($alice)->create(['prompt' => 'alice prompt']);
    Generation::factory()->for($bob)->create(['prompt' => 'bob prompt']);

    $this->actingAs($alice)->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('generations/Index')
            ->has('generations.data', 1)
            ->where('generations.data.0.prompt', 'alice prompt'));
});

it('does not let a user retry someone else\'s generation', function () {
    $bobs = Generation::factory()->for(User::factory())->create(['status' => GenerationStatus::Failed]);

    $this->actingAs(fundedUser())->post(route('generations.retry', $bobs))->assertNotFound();
    expect(Generation::query()->count())->toBe(1);
});

it('does not prefill the generate form from someone else\'s generation', function () {
    $bobs = Generation::factory()->for(User::factory())->create(['prompt' => 'secret']);

    $this->actingAs(User::factory()->create())->get(route('generations.create', ['from' => $bobs->id]))
        ->assertInertia(fn (Assert $page) => $page->where('prefill', null));
});

it('only shows the user\'s own ledger', function () {
    $alice = fundedUser('5.00');
    fundedUser('10.00');

    $this->actingAs($alice)->get(route('credits.index'))
        ->assertInertia(fn (Assert $page) => $page->has('transactions.data', 1));
});

it('hides the admin report from non-admins', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.report'))->assertForbidden();
});

it('makes an admin with the artisan command', function () {
    $user = User::factory()->create(['email' => 'boss@example.com']);

    $this->artisan('app:make-admin', ['email' => 'boss@example.com'])->assertSuccessful();
    $this->artisan('app:make-admin', ['email' => 'nobody@example.com'])->assertFailed();

    expect($user->refresh()->is_admin)->toBeTrue();
});

it('shares the balance as a string', function () {
    $this->actingAs(fundedUser('5.00'))->get(route('generations.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.balance_nanos', '5000000000')
            ->where('pricing.rates.reasoning_tokens', '12.50'));
});
