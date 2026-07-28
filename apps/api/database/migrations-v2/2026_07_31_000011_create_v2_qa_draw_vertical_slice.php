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
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->unique()
                ->constrained('users')->restrictOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->string('reason', 500);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at');
            $table->foreignId('enabled_by_admin_id')
                ->constrained('admins')->restrictOnDelete();
            $table->timestampTz('disabled_at')->nullable();
            $table->foreignId('disabled_by_admin_id')->nullable()
                ->constrained('admins')->restrictOnDelete();
            $table->timestampsTz();
        });

        Schema::create('qa_draw_plans', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('gacha_id')
                ->constrained('catalog_gachas')->restrictOnDelete();
            $table->string('status', 16);
            $table->string('title', 191);
            $table->string('reason', 500);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->foreignId('created_by_admin_id')
                ->constrained('admins')->restrictOnDelete();
            $table->foreignId('updated_by_admin_id')
                ->constrained('admins')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['user_id', 'created_at', 'id']);
            $table->index(['gacha_id', 'created_at', 'id']);
        });

        Schema::create('qa_draw_plan_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('qa_draw_plan_id')
                ->constrained('qa_draw_plans')->restrictOnDelete();
            $table->foreignId('gacha_version_prize_id')
                ->constrained('catalog_gacha_version_prizes')->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order');
            $table->unsignedBigInteger('quantity');
            $table->unsignedBigInteger('consumed_count')->default(0);
            $table->foreignId('fixed_image_asset_id')->nullable()
                ->constrained('catalog_presentation_assets')->restrictOnDelete();
            $table->foreignId('fixed_video_asset_id')->nullable()
                ->constrained('catalog_presentation_assets')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['qa_draw_plan_id', 'sort_order']);
            $table->index(['qa_draw_plan_id', 'consumed_count', 'id']);
        });

        Schema::table('draw_requests', function (Blueprint $table): void {
            $table->boolean('is_qa_draw')->default(false);
            $table->foreignId('qa_test_user_mode_id')->nullable()
                ->constrained('qa_test_user_modes')->restrictOnDelete();
            $table->foreignId('qa_draw_plan_id')->nullable()
                ->constrained('qa_draw_plans')->restrictOnDelete();
            $table->index(['is_qa_draw', 'created_at', 'id']);
            $table->index(['qa_draw_plan_id', 'created_at', 'id']);
        });

        Schema::table('draw_results', function (Blueprint $table): void {
            $table->boolean('is_qa_draw')->default(false);
            $table->foreignId('qa_draw_plan_item_id')->nullable()
                ->constrained('qa_draw_plan_items')->restrictOnDelete();
            $table->index(['is_qa_draw', 'created_at', 'id']);
            $table->index(['qa_draw_plan_item_id', 'created_at', 'id']);
        });

        Schema::create('qa_draw_executions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('draw_request_id')->unique()
                ->constrained('draw_requests')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('gacha_id')
                ->constrained('catalog_gachas')->restrictOnDelete();
            $table->foreignId('qa_test_user_mode_id')
                ->constrained('qa_test_user_modes')->restrictOnDelete();
            $table->foreignId('qa_draw_plan_id')
                ->constrained('qa_draw_plans')->restrictOnDelete();
            $table->unsignedSmallInteger('executed_count');
            $table->timestampTz('executed_at');
            $table->jsonb('metadata_redacted');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['user_id', 'executed_at', 'id']);
            $table->index(['gacha_id', 'executed_at', 'id']);
            $table->index(['qa_draw_plan_id', 'executed_at', 'id']);
        });

        $this->constraints();
        $this->deletionGuards();
    }

    public function down(): void
    {
        foreach ([
            'qa_draw_executions',
            'qa_draw_plan_items',
            'qa_draw_plans',
            'qa_test_user_modes',
        ] as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_reject_delete ON {$table}");
        }
        DB::statement(
            'DROP TRIGGER IF EXISTS qa_draw_executions_reject_update '.
            'ON qa_draw_executions'
        );
        DB::statement('DROP FUNCTION IF EXISTS v2_reject_qa_draw_deletion()');
        DB::statement('DROP FUNCTION IF EXISTS v2_reject_qa_execution_update()');

        Schema::dropIfExists('qa_draw_executions');
        Schema::table('draw_results', function (Blueprint $table): void {
            $table->dropIndex(['qa_draw_plan_item_id', 'created_at', 'id']);
            $table->dropIndex(['is_qa_draw', 'created_at', 'id']);
            $table->dropConstrainedForeignId('qa_draw_plan_item_id');
            $table->dropColumn('is_qa_draw');
        });
        Schema::table('draw_requests', function (Blueprint $table): void {
            $table->dropIndex(['qa_draw_plan_id', 'created_at', 'id']);
            $table->dropIndex(['is_qa_draw', 'created_at', 'id']);
            $table->dropConstrainedForeignId('qa_draw_plan_id');
            $table->dropConstrainedForeignId('qa_test_user_mode_id');
            $table->dropColumn('is_qa_draw');
        });
        Schema::dropIfExists('qa_draw_plan_items');
        Schema::dropIfExists('qa_draw_plans');
        Schema::dropIfExists('qa_test_user_modes');
    }

    private function constraints(): void
    {
        DB::statement(
            'ALTER TABLE qa_test_user_modes ADD CONSTRAINT qa_test_user_mode_values_check CHECK ('.
            'length(btrim(reason)) > 0 AND ends_at > COALESCE(starts_at, created_at) AND '.
            "ends_at <= COALESCE(starts_at, created_at) + INTERVAL '24 hours' AND ".
            '((is_enabled AND disabled_at IS NULL AND disabled_by_admin_id IS NULL) OR '.
            '(NOT is_enabled AND disabled_at IS NOT NULL AND disabled_by_admin_id IS NOT NULL)))'
        );
        DB::statement(
            'ALTER TABLE qa_draw_plans ADD CONSTRAINT qa_draw_plan_values_check CHECK ('.
            "status::text = ANY (ARRAY['active'::text,'paused'::text,".
            "'completed'::text,'disabled'::text]) AND length(btrim(title)) > 0 AND ".
            'length(btrim(reason)) > 0 AND '.
            '(ends_at IS NULL OR ends_at > COALESCE(starts_at, created_at)))'
        );
        DB::statement(
            'ALTER TABLE qa_draw_plan_items ADD CONSTRAINT qa_draw_plan_item_values_check CHECK ('.
            'sort_order > 0 AND quantity > 0 AND consumed_count <= quantity)'
        );
        DB::statement(
            'CREATE UNIQUE INDEX qa_draw_plans_active_user_gacha_unique '.
            "ON qa_draw_plans (user_id, gacha_id) WHERE status = 'active'"
        );
        DB::statement(
            'ALTER TABLE draw_requests ADD CONSTRAINT draw_request_qa_values_check CHECK ('.
            '(is_qa_draw AND qa_test_user_mode_id IS NOT NULL AND qa_draw_plan_id IS NOT NULL) OR '.
            '(NOT is_qa_draw AND qa_test_user_mode_id IS NULL AND qa_draw_plan_id IS NULL))'
        );
        DB::statement(
            'ALTER TABLE draw_results ADD CONSTRAINT draw_result_qa_values_check CHECK ('.
            '(is_qa_draw AND result_type = \'prize\' AND qa_draw_plan_item_id IS NOT NULL) OR '.
            '(NOT is_qa_draw AND qa_draw_plan_item_id IS NULL))'
        );
        DB::statement(
            'ALTER TABLE qa_draw_executions ADD CONSTRAINT qa_draw_execution_values_check CHECK ('.
            "executed_count = ANY (ARRAY[1,5,10,100,1000]) AND ".
            "jsonb_typeof(metadata_redacted) = 'object')"
        );
    }

    private function deletionGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_reject_qa_draw_deletion()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'V2 QA Draw records cannot be physically deleted';
            END;
            $$
        SQL);
        foreach ([
            'qa_test_user_modes',
            'qa_draw_plans',
            'qa_draw_plan_items',
            'qa_draw_executions',
        ] as $table) {
            DB::statement(
                "CREATE TRIGGER {$table}_reject_delete BEFORE DELETE ON {$table} ".
                'FOR EACH ROW EXECUTE FUNCTION v2_reject_qa_draw_deletion()'
            );
            DB::statement("REVOKE DELETE, TRUNCATE ON {$table} FROM PUBLIC");
        }
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_reject_qa_execution_update()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'V2 QA Draw execution history is immutable';
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER qa_draw_executions_reject_update '.
            'BEFORE UPDATE ON qa_draw_executions '.
            'FOR EACH ROW EXECUTE FUNCTION v2_reject_qa_execution_update()'
        );
        DB::statement('REVOKE UPDATE ON qa_draw_executions FROM PUBLIC');
    }
};
