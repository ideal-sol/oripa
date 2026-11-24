<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_conversion_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('event_name', 32);
            $table->timestampTz('occurred_at', 3);
            $table->string('source', 16);
            $table->boolean('suppressed');
            $table->string('suppressed_reason', 32)->nullable();
            $table->string('claim_token_hash', 64)->nullable();
            $table->timestampTz('claim_expires_at', 6)->nullable();
            $table->timestampTz('delivered_at', 6)->nullable();
            $table->timestampsTz(6);
            $table->unique(['user_id', 'event_name']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE user_conversion_events
                ADD CONSTRAINT user_conversion_event_name CHECK (event_name = 'line_friend_added'),
                ADD CONSTRAINT user_conversion_event_source CHECK (source::text IN ('transition'::text,'recovered'::text)),
                ADD CONSTRAINT user_conversion_event_suppression CHECK (
                    suppressed = (suppressed_reason IS NOT NULL)
                    AND (source <> 'recovered' OR suppressed)
                ),
                ADD CONSTRAINT user_conversion_event_claim CHECK (
                    (claim_token_hash IS NULL) = (claim_expires_at IS NULL)
                    AND (claim_token_hash IS NULL OR claim_token_hash ~ '^[0-9a-f]{64}$')
                    AND (NOT suppressed OR (claim_token_hash IS NULL AND delivered_at IS NULL))
                )
            SQL);
    }

    public function down(): void
    {
        throw new LogicException('Retain conversion evidence and use a forward correction.');
    }
};
