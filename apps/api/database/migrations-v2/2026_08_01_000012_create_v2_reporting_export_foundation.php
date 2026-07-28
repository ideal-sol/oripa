<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->string('report_type', 32);
            $table->string('status', 16)->default('queued');
            $table->string('period_type', 8);
            $table->char('period_month', 7)->nullable();
            $table->date('period_date')->nullable();
            $table->string('qa_filter', 8)->default('all');
            $table->char('canonical_filter_hash', 64);
            $table->timestampTz('data_cutoff_at');
            $table->string('query_version', 32);
            $table->foreignId('requested_by_admin_id')
                ->constrained('admins')->restrictOnDelete();
            $table->uuid('request_id');
            $table->foreignId('idempotency_record_id')->unique()
                ->constrained('idempotency_records')->restrictOnDelete();
            $table->unsignedBigInteger('row_count')->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('private_object_key', 512)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('locked_at')->nullable();
            $table->string('locked_by', 128)->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('expires_at');
            $table->string('failure_code', 64)->nullable();
            $table->timestampsTz();
            $table->index(['requested_by_admin_id', 'created_at', 'id']);
            $table->index(['status', 'lease_expires_at', 'id']);
            $table->index(['report_type', 'period_month', 'period_date', 'id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE export_jobs
            ADD CONSTRAINT export_jobs_values_check CHECK (
                report_type::text = ANY (ARRAY[
                    'sales'::text,
                    'adjustments'::text,
                    'point_ledger'::text,
                    'draw_results'::text,
                    'point_snapshots'::text
                ])
                AND status::text = ANY (ARRAY[
                    'queued'::text,
                    'processing'::text,
                    'completed'::text,
                    'failed'::text,
                    'expired'::text
                ])
                AND period_type::text = ANY (ARRAY['month'::text, 'date'::text])
                AND qa_filter::text = ANY (ARRAY['normal'::text, 'qa'::text, 'all'::text])
                AND canonical_filter_hash ~ '^[0-9a-f]{64}$'
                AND query_version ~ '^[a-z0-9][a-z0-9._-]{0,31}$'
                AND attempts <= 3
                AND expires_at > data_cutoff_at
                AND (
                    (period_type = 'month' AND period_month ~ '^[0-9]{4}-(0[1-9]|1[0-2])$' AND period_date IS NULL)
                    OR
                    (period_type = 'date' AND period_month IS NULL AND period_date IS NOT NULL)
                )
                AND (
                    (status = 'queued' AND locked_at IS NULL AND locked_by IS NULL AND lease_expires_at IS NULL
                        AND completed_at IS NULL AND failed_at IS NULL)
                    OR
                    (status = 'processing' AND locked_at IS NOT NULL AND locked_by IS NOT NULL
                        AND lease_expires_at IS NOT NULL AND started_at IS NOT NULL
                        AND completed_at IS NULL AND failed_at IS NULL)
                    OR
                    (status = 'completed' AND locked_at IS NULL AND locked_by IS NULL
                        AND lease_expires_at IS NULL AND completed_at IS NOT NULL
                        AND failed_at IS NULL AND row_count IS NOT NULL AND byte_size IS NOT NULL
                        AND sha256 ~ '^[0-9a-f]{64}$'
                        AND private_object_key LIKE 'v2/private/exports/%')
                    OR
                    (status = 'failed' AND locked_at IS NULL AND locked_by IS NULL
                        AND lease_expires_at IS NULL AND failed_at IS NOT NULL
                        AND failure_code ~ '^[a-z][a-z0-9_.:-]{0,63}$')
                    OR
                    (status = 'expired' AND locked_at IS NULL AND locked_by IS NULL
                        AND lease_expires_at IS NULL AND completed_at IS NOT NULL)
                )
            )
            SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_export_job_transition_guard()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.status = NEW.status THEN
                    RETURN NEW;
                END IF;
                IF NOT (
                    (OLD.status = 'queued' AND NEW.status = 'processing')
                    OR
                    (OLD.status = 'processing' AND NEW.status IN ('queued', 'completed', 'failed'))
                    OR
                    (OLD.status = 'failed' AND NEW.status = 'queued' AND OLD.attempts < 3)
                    OR
                    (OLD.status = 'completed' AND NEW.status = 'expired')
                ) THEN
                    RAISE EXCEPTION 'Invalid Export Job status transition';
                END IF;
                RETURN NEW;
            END;
            $$
            SQL);
        DB::statement(
            'CREATE TRIGGER export_jobs_transition_guard '.
            'BEFORE UPDATE OF status ON export_jobs FOR EACH ROW '.
            'EXECUTE FUNCTION v2_export_job_transition_guard()'
        );

        Schema::table('payments', function (Blueprint $table): void {
            $table->index(['status', 'succeeded_at', 'id'], 'payments_reporting_succeeded');
        });
        Schema::table('payment_adjustments', function (Blueprint $table): void {
            $table->index(
                ['status', 'type', 'succeeded_at', 'id'],
                'payment_adjustments_reporting_succeeded'
            );
        });
        Schema::table('point_ledger_entries', function (Blueprint $table): void {
            $table->index(
                ['business_date', 'point_type', 'entry_type', 'id'],
                'point_ledger_entries_reporting'
            );
        });
        Schema::table('point_operations', function (Blueprint $table): void {
            $table->index(
                ['business_date', 'source_type', 'is_qa', 'id'],
                'point_operations_reporting'
            );
        });
        Schema::table('draw_requests', function (Blueprint $table): void {
            $table->index(
                ['completed_at', 'is_qa_draw', 'id'],
                'draw_requests_reporting'
            );
        });
        Schema::table('draw_results', function (Blueprint $table): void {
            $table->index(
                ['occurred_at', 'is_qa_draw', 'id'],
                'draw_results_reporting'
            );
        });
    }

    public function down(): void
    {
        Schema::table('draw_results', function (Blueprint $table): void {
            $table->dropIndex('draw_results_reporting');
        });
        Schema::table('draw_requests', function (Blueprint $table): void {
            $table->dropIndex('draw_requests_reporting');
        });
        Schema::table('point_operations', function (Blueprint $table): void {
            $table->dropIndex('point_operations_reporting');
        });
        Schema::table('point_ledger_entries', function (Blueprint $table): void {
            $table->dropIndex('point_ledger_entries_reporting');
        });
        Schema::table('payment_adjustments', function (Blueprint $table): void {
            $table->dropIndex('payment_adjustments_reporting_succeeded');
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex('payments_reporting_succeeded');
        });
        Schema::dropIfExists('export_jobs');
        DB::statement('DROP FUNCTION IF EXISTS v2_export_job_transition_guard()');
    }
};
