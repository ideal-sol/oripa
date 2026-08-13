<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE qa_test_user_modes DROP CONSTRAINT IF EXISTS '.
            'qa_test_user_mode_values_check'
        );
        DB::statement('ALTER TABLE qa_test_user_modes ALTER COLUMN ends_at DROP NOT NULL');
        DB::statement('ALTER TABLE qa_test_user_modes DISABLE TRIGGER qa_test_user_modes_revision_guard');
        DB::statement(<<<'SQL'
            UPDATE qa_test_user_modes
            SET is_enabled = CASE WHEN ends_at <= CURRENT_TIMESTAMP THEN false ELSE true END,
                starts_at = CASE WHEN ends_at > CURRENT_TIMESTAMP THEN NULL ELSE starts_at END,
                ends_at = CASE WHEN ends_at > CURRENT_TIMESTAMP THEN NULL ELSE ends_at END,
                disabled_at = CASE
                    WHEN ends_at <= CURRENT_TIMESTAMP THEN COALESCE(disabled_at, ends_at)
                    ELSE NULL
                END,
                disabled_by_admin_id = CASE
                    WHEN ends_at <= CURRENT_TIMESTAMP
                        THEN COALESCE(disabled_by_admin_id, enabled_by_admin_id)
                    ELSE NULL
                END,
                revision = revision + 1,
                updated_at = CURRENT_TIMESTAMP
            WHERE is_enabled = true
        SQL);
        DB::statement('ALTER TABLE qa_test_user_modes ENABLE TRIGGER qa_test_user_modes_revision_guard');
        DB::statement(
            'ALTER TABLE qa_test_user_modes ADD CONSTRAINT qa_test_user_mode_values_check CHECK ('.
            'length(btrim(reason)) > 0 AND '.
            '((is_enabled AND disabled_at IS NULL AND disabled_by_admin_id IS NULL) OR '.
            '(NOT is_enabled AND disabled_at IS NOT NULL AND disabled_by_admin_id IS NOT NULL)))'
        );

        Schema::create('qa_gacha_guarantee_assignments', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('gacha_id')
                ->constrained('catalog_gachas')->restrictOnDelete();
            $table->foreignId('prize_id')
                ->constrained('catalog_prizes')->restrictOnDelete();
            $table->string('status', 16)->default('assigned');
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampTz('assigned_at');
            $table->foreignId('assigned_by_admin_id')
                ->constrained('admins')->restrictOnDelete();
            $table->timestampTz('unassigned_at')->nullable();
            $table->foreignId('unassigned_by_admin_id')->nullable()
                ->constrained('admins')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['user_id', 'gacha_id']);
            $table->index(['gacha_id', 'status', 'id']);
            $table->index(['user_id', 'status', 'id']);
        });
        DB::statement(
            'ALTER TABLE qa_gacha_guarantee_assignments ADD CONSTRAINT '.
            'qa_gacha_guarantee_assignments_values_check CHECK ('.
            "status::text = ANY (ARRAY['assigned'::text, 'unassigned'::text]) AND ".
            'revision >= 1 AND '.
            "((status = 'assigned' AND unassigned_at IS NULL AND ".
            'unassigned_by_admin_id IS NULL) OR '.
            "(status = 'unassigned' AND unassigned_at IS NOT NULL AND ".
            'unassigned_by_admin_id IS NOT NULL)))'
        );
        $this->assignmentGuards();

        Schema::table('draw_requests', function (Blueprint $table): void {
            $table->foreignId('qa_gacha_guarantee_assignment_id')->nullable()
                ->constrained('qa_gacha_guarantee_assignments')->restrictOnDelete();
            $table->index(['qa_gacha_guarantee_assignment_id', 'created_at', 'id']);
        });
        Schema::table('draw_results', function (Blueprint $table): void {
            $table->foreignId('qa_gacha_guarantee_assignment_id')->nullable()
                ->constrained('qa_gacha_guarantee_assignments')->restrictOnDelete();
            $table->index(['qa_gacha_guarantee_assignment_id', 'created_at', 'id']);
        });
        DB::statement(
            'ALTER TABLE qa_draw_executions ALTER COLUMN qa_draw_plan_id DROP NOT NULL'
        );
        Schema::table('qa_draw_executions', function (Blueprint $table): void {
            $table->foreignId('qa_gacha_guarantee_assignment_id')->nullable()
                ->constrained('qa_gacha_guarantee_assignments')->restrictOnDelete();
            $table->index(['qa_gacha_guarantee_assignment_id', 'executed_at', 'id']);
        });
        $this->replaceDrawConstraints(true);
    }

    public function down(): void
    {
        $this->replaceDrawConstraints(false);
        Schema::table('qa_draw_executions', function (Blueprint $table): void {
            $table->dropIndex(['qa_gacha_guarantee_assignment_id', 'executed_at', 'id']);
            $table->dropConstrainedForeignId('qa_gacha_guarantee_assignment_id');
        });
        DB::statement(
            'ALTER TABLE qa_draw_executions ALTER COLUMN qa_draw_plan_id SET NOT NULL'
        );
        Schema::table('draw_results', function (Blueprint $table): void {
            $table->dropIndex(['qa_gacha_guarantee_assignment_id', 'created_at', 'id']);
            $table->dropConstrainedForeignId('qa_gacha_guarantee_assignment_id');
        });
        Schema::table('draw_requests', function (Blueprint $table): void {
            $table->dropIndex(['qa_gacha_guarantee_assignment_id', 'created_at', 'id']);
            $table->dropConstrainedForeignId('qa_gacha_guarantee_assignment_id');
        });

        DB::statement(
            'DROP TRIGGER IF EXISTS qa_gacha_guarantee_assignments_guard '.
            'ON qa_gacha_guarantee_assignments'
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS qa_gacha_guarantee_assignments_reject_delete '.
            'ON qa_gacha_guarantee_assignments'
        );
        DB::statement('DROP FUNCTION IF EXISTS v2_guard_qa_gacha_guarantee_assignment()');
        DB::statement('DROP FUNCTION IF EXISTS v2_reject_qa_gacha_guarantee_assignment_delete()');
        Schema::dropIfExists('qa_gacha_guarantee_assignments');

        DB::statement(
            'ALTER TABLE qa_test_user_modes DROP CONSTRAINT IF EXISTS '.
            'qa_test_user_mode_values_check'
        );
        DB::statement('ALTER TABLE qa_test_user_modes DISABLE TRIGGER qa_test_user_modes_revision_guard');
        DB::statement(<<<'SQL'
            UPDATE qa_test_user_modes
            SET starts_at = CASE WHEN ends_at IS NULL THEN CURRENT_TIMESTAMP ELSE starts_at END,
                ends_at = CASE WHEN ends_at IS NULL
                    THEN CURRENT_TIMESTAMP + INTERVAL '24 hours'
                    ELSE ends_at
                END,
                revision = revision + 1,
                updated_at = CURRENT_TIMESTAMP
            WHERE ends_at IS NULL
        SQL);
        DB::statement('ALTER TABLE qa_test_user_modes ENABLE TRIGGER qa_test_user_modes_revision_guard');
        DB::statement('ALTER TABLE qa_test_user_modes ALTER COLUMN ends_at SET NOT NULL');
        DB::statement(
            'ALTER TABLE qa_test_user_modes ADD CONSTRAINT qa_test_user_mode_values_check CHECK ('.
            'length(btrim(reason)) > 0 AND ends_at > COALESCE(starts_at, created_at) AND '.
            "ends_at <= COALESCE(starts_at, created_at) + INTERVAL '24 hours' AND ".
            '((is_enabled AND disabled_at IS NULL AND disabled_by_admin_id IS NULL) OR '.
            '(NOT is_enabled AND disabled_at IS NOT NULL AND disabled_by_admin_id IS NOT NULL)))'
        );
    }

    private function assignmentGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_guard_qa_gacha_guarantee_assignment()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE prize_gacha_id bigint;
            BEGIN
                SELECT gacha_id INTO prize_gacha_id
                FROM catalog_prizes
                WHERE id = NEW.prize_id;
                IF prize_gacha_id IS NULL OR prize_gacha_id IS DISTINCT FROM NEW.gacha_id THEN
                    RAISE EXCEPTION 'Cross-Gacha QA Prize assignment is not allowed';
                END IF;
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.user_id IS DISTINCT FROM OLD.user_id
                       OR NEW.gacha_id IS DISTINCT FROM OLD.gacha_id THEN
                        RAISE EXCEPTION 'QA Gacha guarantee assignment identity is immutable';
                    END IF;
                    IF NEW.revision <> OLD.revision + 1 THEN
                        RAISE EXCEPTION 'QA Gacha guarantee assignment revision must advance exactly once';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER qa_gacha_guarantee_assignments_guard '.
            'BEFORE INSERT OR UPDATE ON qa_gacha_guarantee_assignments FOR EACH ROW '.
            'EXECUTE FUNCTION v2_guard_qa_gacha_guarantee_assignment()'
        );
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_reject_qa_gacha_guarantee_assignment_delete()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'QA Gacha guarantee assignments cannot be physically deleted';
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER qa_gacha_guarantee_assignments_reject_delete '.
            'BEFORE DELETE ON qa_gacha_guarantee_assignments FOR EACH ROW '.
            'EXECUTE FUNCTION v2_reject_qa_gacha_guarantee_assignment_delete()'
        );
        DB::statement(
            'REVOKE DELETE, TRUNCATE ON qa_gacha_guarantee_assignments FROM PUBLIC'
        );
    }

    private function replaceDrawConstraints(bool $withGuarantee): void
    {
        foreach ([
            ['draw_requests', 'draw_request_qa_values_check'],
            ['draw_results', 'draw_result_qa_values_check'],
            ['qa_draw_executions', 'qa_draw_execution_values_check'],
        ] as [$table, $constraint]) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
        }
        if (! $withGuarantee) {
            DB::statement(
                'ALTER TABLE draw_requests ADD CONSTRAINT draw_request_qa_values_check CHECK ('.
                '(is_qa_draw AND qa_test_user_mode_id IS NOT NULL AND qa_draw_plan_id IS NOT NULL) OR '.
                '(NOT is_qa_draw AND qa_test_user_mode_id IS NULL AND qa_draw_plan_id IS NULL))'
            );
            DB::statement(
                'ALTER TABLE draw_results ADD CONSTRAINT draw_result_qa_values_check CHECK ('.
                "(is_qa_draw AND result_type = 'prize' AND qa_draw_plan_item_id IS NOT NULL) OR ".
                '(NOT is_qa_draw AND qa_draw_plan_item_id IS NULL))'
            );
            DB::statement(
                'ALTER TABLE qa_draw_executions ADD CONSTRAINT qa_draw_execution_values_check CHECK ('.
                'executed_count = ANY (ARRAY[1,5,10,100,1000]) AND '.
                "jsonb_typeof(metadata_redacted) = 'object')"
            );

            return;
        }
        DB::statement(
            'ALTER TABLE draw_requests ADD CONSTRAINT draw_request_qa_values_check CHECK ('.
            '(is_qa_draw AND qa_test_user_mode_id IS NOT NULL AND '.
            '((qa_draw_plan_id IS NOT NULL AND qa_gacha_guarantee_assignment_id IS NULL) OR '.
            '(qa_draw_plan_id IS NULL AND qa_gacha_guarantee_assignment_id IS NOT NULL))) OR '.
            '(NOT is_qa_draw AND qa_test_user_mode_id IS NULL AND qa_draw_plan_id IS NULL AND '.
            'qa_gacha_guarantee_assignment_id IS NULL))'
        );
        DB::statement(
            'ALTER TABLE draw_results ADD CONSTRAINT draw_result_qa_values_check CHECK ('.
            "(is_qa_draw AND result_type = 'prize' AND ".
            '((qa_draw_plan_item_id IS NOT NULL AND qa_gacha_guarantee_assignment_id IS NULL) OR '.
            '(qa_draw_plan_item_id IS NULL AND qa_gacha_guarantee_assignment_id IS NOT NULL))) OR '.
            '(NOT is_qa_draw AND qa_draw_plan_item_id IS NULL AND '.
            'qa_gacha_guarantee_assignment_id IS NULL))'
        );
        DB::statement(
            'ALTER TABLE qa_draw_executions ADD CONSTRAINT qa_draw_execution_values_check CHECK ('.
            '((qa_draw_plan_id IS NOT NULL AND qa_gacha_guarantee_assignment_id IS NULL AND '.
            'executed_count = ANY (ARRAY[1,5,10,100,1000])) OR '.
            '(qa_draw_plan_id IS NULL AND qa_gacha_guarantee_assignment_id IS NOT NULL AND '.
            'executed_count BETWEEN 1 AND 1000)) AND '.
            "jsonb_typeof(metadata_redacted) = 'object')"
        );
    }
};
