<?php

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->ledger = app(CreditLedger::class);
});

it('buys credits once per client key', function () {
    $user = User::factory()->create();

    $this->ledger->purchase($user, Money::fromUsd('5.00'), 'click-1');
    $this->ledger->purchase($user, Money::fromUsd('5.00'), 'click-1');
    $this->ledger->purchase($user, Money::fromUsd('10.00'), 'click-2');

    expect($user->refresh()->balance_nanos)->toBe(15_000_000_000)
        ->and(CreditTransaction::query()->count())->toBe(2);
});

it('grants the signup bonus once', function () {
    config(['pixelforge.credits.signup_bonus_usd' => '1.00']);
    $user = User::factory()->create();

    $this->ledger->grantSignupBonus($user);
    $this->ledger->grantSignupBonus($user);

    expect($user->refresh()->balance_nanos)->toBe(1_000_000_000);
});

it('holds, then settles with the exact charge, and keeps the cached balance equal to the ledger sum', function () {
    $user = User::factory()->create();
    $this->ledger->purchase($user, Money::fromUsd('1.00'), 'k');
    $generation = Generation::factory()->for($user)->create(['hold_nanos' => 86_500_000]);

    DB::transaction(fn () => $this->ledger->hold($this->ledger->lockUser($user->id), $generation, Money::nanos(86_500_000)));
    expect($user->refresh()->balance_nanos)->toBe(1_000_000_000 - 86_500_000);

    DB::transaction(fn () => $this->ledger->settle($generation, Money::nanos(61_500_000)));
    DB::transaction(fn () => $this->ledger->settle($generation, Money::nanos(61_500_000)));

    expect($user->refresh()->balance_nanos)->toBe(1_000_000_000 - 61_500_000)
        ->and((int) CreditTransaction::query()->where('user_id', $user->id)->sum('amount_nanos'))->toBe($user->balance_nanos)
        ->and(CreditTransaction::query()->where('generation_id', $generation->id)->orderBy('id')->pluck('type')->map->value->all())
        ->toBe(['hold', 'hold_release', 'charge']);
});

it('releases the full hold on failure', function () {
    $user = User::factory()->create();
    $this->ledger->purchase($user, Money::fromUsd('1.00'), 'k');
    $generation = Generation::factory()->for($user)->create();

    DB::transaction(fn () => $this->ledger->hold($this->ledger->lockUser($user->id), $generation, Money::nanos(86_500_000)));
    DB::transaction(fn () => $this->ledger->release($generation));
    DB::transaction(fn () => $this->ledger->release($generation));

    expect($user->refresh()->balance_nanos)->toBe(1_000_000_000);
});

it('refuses a second charge for a generation at the database level', function () {
    $user = User::factory()->create();
    $generation = Generation::factory()->for($user)->create();

    $insert = fn (string $key) => DB::table('credit_transactions')->insert([
        'user_id' => $user->id,
        'type' => CreditTransactionType::Charge->value,
        'amount_nanos' => -1,
        'balance_after_nanos' => 0,
        'generation_id' => $generation->id,
        'idempotency_key' => $key,
    ]);

    $insert('a');
    $insert('b');
})->throws(UniqueConstraintViolationException::class);

it('refuses a hold larger than the balance', function () {
    $user = User::factory()->create();
    $generation = Generation::factory()->for($user)->create();

    DB::transaction(fn () => $this->ledger->hold($this->ledger->lockUser($user->id), $generation, Money::nanos(1)));
})->throws(LogicException::class);

it('gives new registrations the signup bonus', function () {
    config(['pixelforge.credits.signup_bonus_usd' => '1.00']);

    $this->post(route('register.store'), [
        'name' => 'New', 'email' => 'new@example.com', 'password' => 'password', 'password_confirmation' => 'password',
    ]);

    expect(User::query()->where('email', 'new@example.com')->value('balance_nanos'))->toBe(1_000_000_000);
});
