<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gacha_ranks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gacha_id')->constrained()->cascadeOnDelete();
            $table->string('rank_key');
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->text('image_url')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestamps();

            $table->unique(['gacha_id', 'rank_key']);
        });

        Schema::create('gacha_prizes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gacha_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rank_id')->constrained('gacha_ranks')->restrictOnDelete();
            $table->string('name');
            $table->text('image_url');
            $table->integer('max_win_count');
            $table->integer('won_count')->default(0);
            $table->integer('cost_price');
            $table->integer('display_price')->nullable();
            $table->integer('exchange_point')->nullable();
            $table->string('condition');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_visible')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['gacha_id', 'rank_id']);
            $table->index(['gacha_id', 'is_active']);
        });

        DB::statement('ALTER TABLE gacha_ranks ADD CONSTRAINT gacha_ranks_sort_order_non_negative CHECK (sort_order >= 0)');
        DB::statement('ALTER TABLE gacha_prizes ADD CONSTRAINT gacha_prizes_counts_check CHECK (max_win_count >= 0 AND won_count >= 0 AND won_count <= max_win_count)');
        DB::statement('ALTER TABLE gacha_prizes ADD CONSTRAINT gacha_prizes_amounts_non_negative CHECK (cost_price >= 0 AND (display_price IS NULL OR display_price >= 0) AND (exchange_point IS NULL OR exchange_point >= 0))');
    }

    public function down(): void
    {
        Schema::dropIfExists('gacha_prizes');
        Schema::dropIfExists('gacha_ranks');
    }
};
