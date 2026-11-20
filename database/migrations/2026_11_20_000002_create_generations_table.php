<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pixelforge_id')->nullable()->unique();
            $table->string('idempotency_key')->unique();
            $table->uuid('retry_of_id')->nullable();
            $table->string('status', 16)->index();

            $table->text('prompt');
            $table->string('size', 16);
            $table->string('quality', 8);
            $table->json('reference_images');
            $table->json('rates')->nullable();

            $table->bigInteger('estimated_min_nanos');
            $table->bigInteger('estimated_expected_nanos');
            $table->bigInteger('estimated_max_nanos');
            $table->bigInteger('hold_nanos');
            $table->bigInteger('provider_cost_nanos')->nullable();
            $table->bigInteger('charged_nanos')->nullable();

            $table->unsignedInteger('input_text_tokens')->nullable();
            $table->unsignedInteger('input_image_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('reasoning_tokens')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->unsignedSmallInteger('submit_attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('slot_claimed_at')->nullable();
            $table->timestamp('last_polled_at')->nullable();

            $table->timestamp('pixelforge_created_at')->nullable();
            $table->timestamp('pixelforge_started_at')->nullable();
            $table->timestamp('pixelforge_completed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
