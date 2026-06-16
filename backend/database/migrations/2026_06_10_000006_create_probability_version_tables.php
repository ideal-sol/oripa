<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gacha_probability_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gacha_id')->constrained()->cascadeOnDelete();
            $table->integer('version_number');
            $table->string('status')->default('draft');
            $table->string('snapshot_hash', 128)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->text('change_reason')->nullable();
            $table->timestamps();

            $table->unique(['gacha_id', 'version_number']);
            $table->index(['gacha_id', 'status']);
        });

        Schema::create('gacha_probability_version_stages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('probability_version_id')->constrained('gacha_probability_versions')->cascadeOnDelete();
            $table->string('stage_key');
            $table->string('name');
            $table->string('condition_type')->default('sold_count');
            $table->integer('min_draw_number');
            $table->integer('max_draw_number')->nullable();
            $table->integer('sort_order')->default(0);

            $table->unique(['probability_version_id', 'stage_key']);
            $table->index(['probability_version_id', 'min_draw_number', 'max_draw_number']);
        });

        Schema::create('gacha_probability_version_prize_probabilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('probability_version_stage_id')->constrained('gacha_probability_version_stages')->cascadeOnDelete();
            $table->foreignId('prize_id')->nullable()->constrained('gacha_prizes')->restrictOnDelete();
            $table->boolean('is_minimum_guarantee')->default(false);
            $table->integer('probability_ppm');

            $table->unique(['probability_version_stage_id', 'prize_id'], 'prob_stage_prize_unique');
        });

        Schema::table('gachas', function (Blueprint $table): void {
            $table->foreign('current_probability_version_id', 'gachas_current_probability_version_fk')
                ->references('id')
                ->on('gacha_probability_versions')
                ->nullOnDelete();
        });

        DB::statement("ALTER TABLE gacha_probability_versions ADD CONSTRAINT gacha_probability_versions_status_check CHECK (status IN ('draft', 'published', 'archived'))");
        DB::statement('ALTER TABLE gacha_probability_versions ADD CONSTRAINT gacha_probability_versions_version_positive CHECK (version_number >= 1)');
        DB::statement("ALTER TABLE gacha_probability_version_stages ADD CONSTRAINT gacha_probability_version_stages_condition_type_check CHECK (condition_type IN ('sold_count'))");
        DB::statement('ALTER TABLE gacha_probability_version_stages ADD CONSTRAINT gacha_probability_version_stages_range_check CHECK (min_draw_number >= 1 AND (max_draw_number IS NULL OR max_draw_number >= min_draw_number))');
        DB::statement('ALTER TABLE gacha_probability_version_prize_probabilities ADD CONSTRAINT gacha_probability_ppm_range_check CHECK (probability_ppm >= 0 AND probability_ppm <= 1000000)');
        DB::statement("ALTER TABLE gacha_probability_version_prize_probabilities ADD CONSTRAINT gacha_probability_minimum_row_check CHECK ((is_minimum_guarantee = true AND prize_id IS NULL) OR (is_minimum_guarantee = false AND prize_id IS NOT NULL))");
        DB::statement('CREATE UNIQUE INDEX prob_stage_one_minimum_unique ON gacha_probability_version_prize_probabilities (probability_version_stage_id) WHERE is_minimum_guarantee = true');
    }

    public function down(): void
    {
        Schema::table('gachas', function (Blueprint $table): void {
            $table->dropForeign('gachas_current_probability_version_fk');
        });

        Schema::dropIfExists('gacha_probability_version_prize_probabilities');
        Schema::dropIfExists('gacha_probability_version_stages');
        Schema::dropIfExists('gacha_probability_versions');
    }
};
