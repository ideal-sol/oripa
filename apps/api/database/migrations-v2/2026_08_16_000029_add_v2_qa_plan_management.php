<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qa_test_user_modes', function (Blueprint $table): void {
            $table->unsignedBigInteger('revision')->default(1);
        });
        Schema::table('qa_draw_plans', function (Blueprint $table): void {
            $table->string('code', 64)->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampTz('archived_at')->nullable();
            $table->foreignId('archived_by_admin_id')->nullable()
                ->constrained('admins')->restrictOnDelete();
        });
        DB::statement(
            "UPDATE qa_draw_plans SET code = 'QA-' || upper(replace(public_id::text, '-', ''))"
        );
        Schema::table('qa_draw_plans', function (Blueprint $table): void {
            $table->string('code', 64)->nullable(false)->change();
            $table->unique('code');
        });

        Schema::create('qa_draw_plan_assignments', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('qa_draw_plan_id')
                ->constrained('qa_draw_plans')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 16)->default('assigned');
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampTz('assigned_at');
            $table->foreignId('assigned_by_admin_id')
                ->constrained('admins')->restrictOnDelete();
            $table->timestampTz('unassigned_at')->nullable();
            $table->foreignId('unassigned_by_admin_id')->nullable()
                ->constrained('admins')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['qa_draw_plan_id', 'user_id']);
            $table->index(['user_id', 'status', 'id']);
            $table->index(['qa_draw_plan_id', 'status', 'id']);
        });
        DB::table('qa_draw_plans')->orderBy('id')->chunkById(250, function ($plans): void {
            $rows = [];
            foreach ($plans as $plan) {
                $rows[] = [
                    'public_id' => (string) Str::uuid7(),
                    'qa_draw_plan_id' => $plan->id,
                    'user_id' => $plan->user_id,
                    'status' => 'assigned',
                    'revision' => 1,
                    'assigned_at' => $plan->created_at,
                    'assigned_by_admin_id' => $plan->created_by_admin_id,
                    'created_at' => $plan->created_at,
                    'updated_at' => $plan->updated_at,
                ];
            }
            if ($rows !== []) {
                DB::table('qa_draw_plan_assignments')->insert($rows);
            }
        });

        $this->constraints();
        $this->guards();
    }

    public function down(): void
    {
        DB::statement(
            'DROP TRIGGER IF EXISTS qa_draw_plan_assignments_guard '.
            'ON qa_draw_plan_assignments'
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS qa_draw_plan_assignments_reject_delete '.
            'ON qa_draw_plan_assignments'
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS qa_draw_plans_management_guard ON qa_draw_plans'
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS qa_test_user_modes_revision_guard ON qa_test_user_modes'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS v2_guard_qa_plan_assignment_update()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS v2_guard_qa_plan_management_update()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS v2_reject_qa_plan_assignment_delete()'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS v2_guard_qa_test_user_mode_revision()'
        );
        Schema::dropIfExists('qa_draw_plan_assignments');
        Schema::table('qa_draw_plans', function (Blueprint $table): void {
            $table->dropUnique(['code']);
            $table->dropConstrainedForeignId('archived_by_admin_id');
            $table->dropColumn(['code', 'revision', 'archived_at']);
        });
        Schema::table('qa_test_user_modes', function (Blueprint $table): void {
            $table->dropColumn('revision');
        });
    }

    private function constraints(): void
    {
        DB::statement(
            'ALTER TABLE qa_test_user_modes ADD CONSTRAINT '.
            'qa_test_user_modes_revision_check CHECK (revision >= 1)'
        );
        DB::statement(
            'ALTER TABLE qa_draw_plans ADD CONSTRAINT qa_draw_plans_management_check CHECK ('.
            "revision >= 1 AND code = upper(code) AND code ~ '^[A-Z0-9][A-Z0-9._-]{0,63}$' AND ".
            '((archived_at IS NULL AND archived_by_admin_id IS NULL) OR '.
            '(archived_at IS NOT NULL AND archived_by_admin_id IS NOT NULL)))'
        );
        DB::statement(
            'ALTER TABLE qa_draw_plan_assignments ADD CONSTRAINT '.
            'qa_draw_plan_assignments_values_check CHECK ('.
            "status::text = ANY (ARRAY['assigned'::text, 'unassigned'::text]) AND ".
            'revision >= 1 AND '.
            "((status = 'assigned' AND unassigned_at IS NULL AND ".
            'unassigned_by_admin_id IS NULL) OR '.
            "(status = 'unassigned' AND unassigned_at IS NOT NULL AND ".
            'unassigned_by_admin_id IS NOT NULL)))'
        );
    }

    private function guards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_guard_qa_test_user_mode_revision()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.revision <> OLD.revision + 1 THEN
                    RAISE EXCEPTION 'QA Test User Mode revision must advance exactly once';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER qa_test_user_modes_revision_guard '.
            'BEFORE UPDATE ON qa_test_user_modes FOR EACH ROW '.
            'EXECUTE FUNCTION v2_guard_qa_test_user_mode_revision()'
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_guard_qa_plan_management_update()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.code IS DISTINCT FROM OLD.code
                    OR NEW.user_id IS DISTINCT FROM OLD.user_id
                    OR NEW.gacha_id IS DISTINCT FROM OLD.gacha_id THEN
                    RAISE EXCEPTION 'QA Plan identity is immutable';
                END IF;
                IF NEW.revision <> OLD.revision + 1 THEN
                    RAISE EXCEPTION 'QA Plan revision must advance exactly once';
                END IF;
                IF NOT (
                    (OLD.status = 'active'
                        AND NEW.status IN ('active', 'paused', 'completed', 'disabled'))
                    OR (OLD.status = 'paused'
                        AND NEW.status IN ('paused', 'active', 'completed', 'disabled'))
                    OR (OLD.status = 'completed' AND NEW.status = 'completed')
                    OR (OLD.status = 'disabled' AND NEW.status = 'disabled')
                ) THEN
                    RAISE EXCEPTION 'QA Plan status transition is invalid';
                END IF;
                IF OLD.status IN ('completed', 'disabled') THEN
                    RAISE EXCEPTION 'Terminal QA Plans are immutable';
                END IF;
                IF OLD.archived_at IS NOT NULL THEN
                    RAISE EXCEPTION 'Archived QA Plans are immutable';
                END IF;
                IF NEW.archived_at IS NOT NULL AND EXISTS (
                    SELECT 1 FROM qa_draw_executions execution
                    WHERE execution.qa_draw_plan_id = OLD.id
                ) THEN
                    RAISE EXCEPTION 'Executed QA Plans cannot be archived';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER qa_draw_plans_management_guard '.
            'BEFORE UPDATE ON qa_draw_plans FOR EACH ROW '.
            'EXECUTE FUNCTION v2_guard_qa_plan_management_update()'
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_guard_qa_plan_assignment_update()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.qa_draw_plan_id IS DISTINCT FROM OLD.qa_draw_plan_id
                    OR NEW.user_id IS DISTINCT FROM OLD.user_id THEN
                    RAISE EXCEPTION 'QA Plan assignment identity is immutable';
                END IF;
                IF NEW.revision <> OLD.revision + 1 THEN
                    RAISE EXCEPTION 'QA Plan assignment revision must advance exactly once';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER qa_draw_plan_assignments_guard '.
            'BEFORE UPDATE ON qa_draw_plan_assignments FOR EACH ROW '.
            'EXECUTE FUNCTION v2_guard_qa_plan_assignment_update()'
        );
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_reject_qa_plan_assignment_delete()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'QA Plan assignments cannot be physically deleted';
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER qa_draw_plan_assignments_reject_delete '.
            'BEFORE DELETE ON qa_draw_plan_assignments FOR EACH ROW '.
            'EXECUTE FUNCTION v2_reject_qa_plan_assignment_delete()'
        );
        DB::statement(
            'REVOKE DELETE, TRUNCATE ON qa_draw_plan_assignments FROM PUBLIC'
        );
    }
};
