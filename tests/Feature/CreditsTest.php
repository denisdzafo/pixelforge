<?php

use App\Models\User;
use Illuminate\Support\Str;

it('buys a credit pack once per click', function () {
    $user = User::factory()->create();
    $key = (string) Str::uuid();

    $this->actingAs($user)->post(route('credits.purchase'), ['pack' => '10.00', 'idempotency_key' => $key])->assertRedirect();
    $this->actingAs($user)->post(route('credits.purchase'), ['pack' => '10.00', 'idempotency_key' => $key])->assertRedirect();

    expect($user->refresh()->balance_nanos)->toBe(10_000_000_000);
});

it('only sells the configured packs', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('credits.purchase'), ['pack' => '1000000.00', 'idempotency_key' => (string) Str::uuid()])
        ->assertSessionHasErrors('pack');
});
