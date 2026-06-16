<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gacha_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestamps();
        });

        Schema::create('gachas', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->foreignId('category_id')->constrained('gacha_categories')->restrictOnDelete();
            $table->integer('price');
            $table->integer('total_count');
            $table->integer('sold_count')->default(0);
            $table->string('probability_mode')->default('single');
            $table->unsignedBigInteger('current_probability_version_id')->nullable();
            $table->string('minimum_guarantee_type');
            $table->integer('minimum_guarantee_value');
            $table->integer('minimum_guarantee_cost')->default(0);
            $table->string('status')->default('draft');
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->text('description')->nullable();
            $table->text('caution')->nullable();
            $table->text('main_image_url')->nullable();
            $table->decimal('target_margin', 6, 2)->nullable();
            $table->timestamps();

            $table->index(['status', 'start_at']);
            $table->index('current_probability_version_id');
        });

        DB::statement("ALTER TABLE gachas ADD CONSTRAINT gachas_probability_mode_check CHECK (probability_mode IN ('single', 'sold_count_stage'))");
        DB::statement("ALTER TABLE gachas ADD CONSTRAINT gachas_minimum_guarantee_type_check CHECK (minimum_guarantee_type IN ('point', 'prize'))");
        DB::statement("ALTER TABLE gachas ADD CONSTRAINT gachas_status_check CHECK (status IN ('draft', 'scheduled', 'active', 'paused', 'sold_out', 'ended', 'hidden'))");
        DB::statement('ALTER TABLE gachas ADD CONSTRAINT gachas_counts_check CHECK (price >= 0 AND total_count > 0 AND sold_count >= 0 AND sold_count <= total_count)');
        DB::statement('ALTER TABLE gachas ADD CONSTRAINT gachas_minimum_guarantee_non_negative CHECK (minimum_guarantee_value >= 0 AND minimum_guarantee_cost >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('gachas');
        Schema::dropIfExists('gacha_categories');
    }
};
