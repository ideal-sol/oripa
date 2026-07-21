<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qa_test_user_modes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->text('reason');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at');
            $table->foreignId('enabled_by_admin_user_id')->constrained('admin_users')->restrictOnDelete();
            $table->foreignId('disabled_by_admin_user_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();

            $table->index(['is_enabled', 'ends_at']);
        });

        Schema::create('qa_draw_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('gacha_id')->constrained('gachas')->restrictOnDelete();
            $table->string('status')->default('active');
            $table->string('title')->nullable();
            $table->text('reason');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('created_by_admin_user_id')->constrained('admin_users')->restrictOnDelete();
            $table->foreignId('updated_by_admin_user_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'gacha_id', 'status']);
            $table->index(['gacha_id', 'status']);
        });

        Schema::create('qa_draw_plan_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('qa_draw_plan_id')->constrained('qa_draw_plans')->restrictOnDelete();
            $table->unsignedInteger('sort_order');
            $table->foreignId('gacha_prize_id')->constrained('gacha_prizes')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('consumed_count')->default(0);
            $table->foreignId('rank_image_asset_id')->nullable()->constrained('rank_assets')->restrictOnDelete();
            $table->foreignId('draw_video_asset_id')->nullable()->constrained('rank_assets')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['qa_draw_plan_id', 'sort_order']);
            $table->index(['qa_draw_plan_id', 'sort_order']);
            $table->index('gacha_prize_id');
        });

        Schema::table('draw_requests', function (Blueprint $table): void {
            $table->boolean('is_qa_draw')->default(false)->after('consumed_point_total');
            $table->foreignId('qa_test_user_mode_id')->nullable()->after('is_qa_draw')->constrained('qa_test_user_modes')->restrictOnDelete();
            $table->foreignId('qa_draw_plan_id')->nullable()->after('qa_test_user_mode_id')->constrained('qa_draw_plans')->restrictOnDelete();

            $table->index(['is_qa_draw', 'created_at']);
            $table->index('qa_draw_plan_id');
        });

        Schema::table('draw_results', function (Blueprint $table): void {
            $table->boolean('is_qa_draw')->default(false)->after('selected_draw_video_url');
            $table->foreignId('qa_draw_plan_item_id')->nullable()->after('is_qa_draw')->constrained('qa_draw_plan_items')->restrictOnDelete();

            $table->index(['is_qa_draw', 'created_at']);
            $table->index('qa_draw_plan_item_id');
        });

        Schema::create('qa_draw_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('qa_test_user_mode_id')->constrained('qa_test_user_modes')->restrictOnDelete();
            $table->foreignId('qa_draw_plan_id')->constrained('qa_draw_plans')->restrictOnDelete();
            $table->foreignId('draw_request_id')->unique()->constrained('draw_requests')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('gacha_id')->constrained('gachas')->restrictOnDelete();
            $table->unsignedInteger('draw_count');
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['gacha_id', 'created_at']);
            $table->index(['qa_draw_plan_id', 'created_at']);
        });

        DB::statement('ALTER TABLE qa_test_user_modes ADD CONSTRAINT qa_test_user_modes_time_check CHECK (starts_at IS NULL OR ends_at > starts_at)');
        DB::statement("ALTER TABLE qa_draw_plans ADD CONSTRAINT qa_draw_plans_status_check CHECK (status IN ('active', 'paused', 'completed', 'disabled'))");
        DB::statement('ALTER TABLE qa_draw_plan_items ADD CONSTRAINT qa_draw_plan_items_counts_check CHECK (quantity > 0 AND consumed_count >= 0 AND consumed_count <= quantity)');
        DB::statement("CREATE UNIQUE INDEX qa_draw_plans_active_user_gacha_unique ON qa_draw_plans (user_id, gacha_id) WHERE status = 'active'");
    }

    public function down(): void
    {
        Schema::dropIfExists('qa_draw_executions');

        Schema::table('draw_results', function (Blueprint $table): void {
            $table->dropIndex(['is_qa_draw', 'created_at']);
            $table->dropIndex(['qa_draw_plan_item_id']);
            $table->dropConstrainedForeignId('qa_draw_plan_item_id');
            $table->dropColumn('is_qa_draw');
        });

        Schema::table('draw_requests', function (Blueprint $table): void {
            $table->dropIndex(['is_qa_draw', 'created_at']);
            $table->dropIndex(['qa_draw_plan_id']);
            $table->dropConstrainedForeignId('qa_draw_plan_id');
            $table->dropConstrainedForeignId('qa_test_user_mode_id');
            $table->dropColumn('is_qa_draw');
        });

        Schema::dropIfExists('qa_draw_plan_items');
        Schema::dropIfExists('qa_draw_plans');
        Schema::dropIfExists('qa_test_user_modes');
    }
};
