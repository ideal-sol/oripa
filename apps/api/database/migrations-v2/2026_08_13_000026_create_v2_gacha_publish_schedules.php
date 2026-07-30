<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_gacha_publish_schedules', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('gacha_id')
                ->constrained('catalog_gachas')->restrictOnDelete();
            $table->foreignId('gacha_version_id')
                ->constrained('catalog_gacha_versions')->restrictOnDelete();
            $table->foreignId('probability_version_id')
                ->constrained('catalog_probability_versions')->restrictOnDelete();
            $table->string('status', 16)->default('scheduled');
            $table->timestampTz('scheduled_for');
            $table->timestampTz('next_attempt_at');
            $table->unsignedBigInteger('expected_gacha_revision');
            $table->unsignedBigInteger('expected_version_revision');
            $table->unsignedBigInteger('revision')->default(1);
            $table->foreignId('requested_by_admin_id')
                ->constrained('admins')->restrictOnDelete();
            $table->foreignId('cancelled_by_admin_id')->nullable()
                ->constrained('admins')->restrictOnDelete();
            $table->uuid('request_id');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('locked_at')->nullable();
            $table->char('locked_by_hash', 64)->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->timestampsTz();
            $table->index(['status', 'next_attempt_at', 'lease_expires_at', 'id']);
            $table->index(['gacha_version_id', 'created_at', 'id']);
        });

        DB::statement(
            "CREATE UNIQUE INDEX catalog_gacha_publish_schedules_active_gacha_unique ".
            "ON catalog_gacha_publish_schedules (gacha_id) ".
            "WHERE status::text = ANY (ARRAY['scheduled'::text, 'processing'::text])"
        );
        DB::statement(
            "CREATE UNIQUE INDEX catalog_gacha_publish_schedules_active_version_unique ".
            "ON catalog_gacha_publish_schedules (gacha_version_id) ".
            "WHERE status::text = ANY (ARRAY['scheduled'::text, 'processing'::text])"
        );
        DB::statement(<<<'SQL'
            ALTER TABLE catalog_gacha_publish_schedules
            ADD CONSTRAINT catalog_gacha_publish_schedules_values_check CHECK (
                status::text = ANY (ARRAY[
                    'scheduled'::text,
                    'processing'::text,
                    'completed'::text,
                    'cancelled'::text,
                    'failed'::text
                ])
                AND expected_gacha_revision::numeric > 0::numeric
                AND expected_version_revision::numeric > 0::numeric
                AND revision::numeric > 0::numeric
                AND attempts::numeric >= 0::numeric
                AND attempts::numeric <= 3::numeric
                AND next_attempt_at >= scheduled_for
                AND (
                    (status = 'scheduled'
                        AND locked_at IS NULL
                        AND locked_by_hash IS NULL
                        AND lease_expires_at IS NULL
                        AND completed_at IS NULL
                        AND cancelled_at IS NULL
                        AND failed_at IS NULL)
                    OR
                    (status = 'processing'
                        AND attempts > 0
                        AND locked_at IS NOT NULL
                        AND locked_by_hash ~ '^[0-9a-f]{64}$'
                        AND lease_expires_at > locked_at
                        AND started_at IS NOT NULL
                        AND completed_at IS NULL
                        AND cancelled_at IS NULL
                        AND failed_at IS NULL)
                    OR
                    (status = 'completed'
                        AND locked_at IS NULL
                        AND locked_by_hash IS NULL
                        AND lease_expires_at IS NULL
                        AND completed_at IS NOT NULL
                        AND cancelled_at IS NULL
                        AND failed_at IS NULL)
                    OR
                    (status = 'cancelled'
                        AND locked_at IS NULL
                        AND locked_by_hash IS NULL
                        AND lease_expires_at IS NULL
                        AND completed_at IS NULL
                        AND cancelled_at IS NOT NULL
                        AND cancelled_by_admin_id IS NOT NULL
                        AND failed_at IS NULL)
                    OR
                    (status = 'failed'
                        AND locked_at IS NULL
                        AND locked_by_hash IS NULL
                        AND lease_expires_at IS NULL
                        AND completed_at IS NULL
                        AND cancelled_at IS NULL
                        AND failed_at IS NOT NULL
                        AND failure_code ~ '^[a-z][a-z0-9_.:-]{0,63}$')
                )
            )
            SQL);

        $this->scheduleGuard();
        $this->draftGuards();
    }

    public function down(): void
    {
        if (DB::table('catalog_gacha_publish_schedules')->exists()) {
            throw new RuntimeException(
                'Cannot roll back Gacha Publish Schedule history.'
            );
        }

        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_gacha_tags_schedule_guard '.
            'ON catalog_gacha_tags'
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_gacha_version_prizes_schedule_guard '.
            'ON catalog_gacha_version_prizes'
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_gacha_versions_schedule_guard '.
            'ON catalog_gacha_versions'
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_gachas_schedule_guard ON catalog_gachas'
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_gacha_publish_schedules_guard '.
            'ON catalog_gacha_publish_schedules'
        );
        DB::statement('DROP FUNCTION IF EXISTS v2_catalog_scheduled_draft_guard()');
        DB::statement('DROP FUNCTION IF EXISTS v2_catalog_publish_schedule_guard()');
        Schema::dropIfExists('catalog_gacha_publish_schedules');
    }

    private function scheduleGuard(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_publish_schedule_guard()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE version_row catalog_gacha_versions%ROWTYPE;
            DECLARE probability_row catalog_probability_versions%ROWTYPE;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Gacha Publish Schedule history cannot be deleted';
                END IF;

                IF TG_OP = 'UPDATE' THEN
                    IF NEW.gacha_id IS DISTINCT FROM OLD.gacha_id
                       OR NEW.gacha_version_id IS DISTINCT FROM OLD.gacha_version_id
                       OR NEW.probability_version_id
                            IS DISTINCT FROM OLD.probability_version_id
                       OR NEW.scheduled_for IS DISTINCT FROM OLD.scheduled_for
                       OR NEW.expected_gacha_revision
                            IS DISTINCT FROM OLD.expected_gacha_revision
                       OR NEW.expected_version_revision
                            IS DISTINCT FROM OLD.expected_version_revision
                       OR NEW.requested_by_admin_id
                            IS DISTINCT FROM OLD.requested_by_admin_id
                       OR NEW.revision IS DISTINCT FROM OLD.revision + 1 THEN
                        RAISE EXCEPTION
                            'Gacha Publish Schedule identity and revision are immutable';
                    END IF;
                    IF NOT (
                        (OLD.status = 'scheduled' AND NEW.status IN ('processing', 'cancelled'))
                        OR
                        (OLD.status = 'processing' AND NEW.status IN ('scheduled', 'completed', 'failed'))
                    ) THEN
                        RAISE EXCEPTION 'Invalid Gacha Publish Schedule transition';
                    END IF;
                ELSIF NEW.scheduled_for <= CURRENT_TIMESTAMP THEN
                    RAISE EXCEPTION 'Gacha Publish Schedule must be in the future';
                END IF;

                IF NEW.status IN ('scheduled', 'processing') THEN
                    SELECT * INTO version_row
                    FROM catalog_gacha_versions
                    WHERE id = NEW.gacha_version_id;
                    SELECT * INTO probability_row
                    FROM catalog_probability_versions
                    WHERE id = NEW.probability_version_id;
                    IF version_row.id IS NULL
                       OR version_row.gacha_id IS DISTINCT FROM NEW.gacha_id
                       OR version_row.status::text <> 'draft'::text
                       OR version_row.archived_at IS NOT NULL
                       OR version_row.revision
                            IS DISTINCT FROM NEW.expected_version_revision
                       OR version_row.published_probability_version_id
                            IS DISTINCT FROM NEW.probability_version_id
                       OR probability_row.id IS NULL
                       OR probability_row.gacha_version_id
                            IS DISTINCT FROM version_row.id
                       OR probability_row.status::text <> 'published'::text
                       OR probability_row.archived_at IS NOT NULL
                       OR probability_row.snapshot_sha256
                            !~ '^[0-9a-f]{64}$' THEN
                        RAISE EXCEPTION
                            'Gacha Publish Schedule requires its Draft and Published Probability';
                    END IF;
                    IF NOT EXISTS (
                        SELECT 1
                        FROM catalog_gachas
                        WHERE id = NEW.gacha_id
                          AND revision = NEW.expected_gacha_revision
                          AND archived_at IS NULL
                    ) THEN
                        RAISE EXCEPTION
                            'Gacha Publish Schedule revision is stale';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER catalog_gacha_publish_schedules_guard '.
            'BEFORE INSERT OR UPDATE OR DELETE '.
            'ON catalog_gacha_publish_schedules FOR EACH ROW '.
            'EXECUTE FUNCTION v2_catalog_publish_schedule_guard()'
        );
    }

    private function draftGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_scheduled_draft_guard()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE parent_gacha_id bigint;
            DECLARE active_schedule catalog_gacha_publish_schedules%ROWTYPE;
            BEGIN
                IF TG_TABLE_NAME = 'catalog_gachas' THEN
                    SELECT * INTO active_schedule
                    FROM catalog_gacha_publish_schedules
                    WHERE gacha_id = OLD.id
                      AND status IN ('scheduled', 'processing')
                    LIMIT 1;
                    IF active_schedule.id IS NULL THEN
                        RETURN NEW;
                    END IF;
                    IF active_schedule.status = 'processing'
                       AND NEW.published_version_id
                            IS DISTINCT FROM OLD.published_version_id
                       AND NEW.active_draw_state_id
                            IS DISTINCT FROM OLD.active_draw_state_id
                       AND NEW.revision IS NOT DISTINCT FROM OLD.revision + 1
                       AND NEW.category_id IS NOT DISTINCT FROM OLD.category_id
                       AND NEW.state IS NOT DISTINCT FROM OLD.state
                       AND NEW.archived_at IS NOT DISTINCT FROM OLD.archived_at THEN
                        RETURN NEW;
                    END IF;
                    RAISE EXCEPTION 'Scheduled Gacha Master is immutable';
                END IF;

                IF TG_TABLE_NAME = 'catalog_gacha_versions' THEN
                    SELECT * INTO active_schedule
                    FROM catalog_gacha_publish_schedules
                    WHERE gacha_version_id = OLD.id
                      AND status IN ('scheduled', 'processing')
                    LIMIT 1;
                    IF active_schedule.id IS NULL THEN
                        RETURN NEW;
                    END IF;
                    IF active_schedule.status = 'processing'
                       AND OLD.status::text = 'draft'::text
                       AND NEW.status::text = 'published'::text
                       AND NEW.published_at IS NOT NULL
                       AND NEW.revision IS NOT DISTINCT FROM OLD.revision + 1
                       AND NEW.gacha_id IS NOT DISTINCT FROM OLD.gacha_id
                       AND NEW.published_probability_version_id
                            IS NOT DISTINCT FROM OLD.published_probability_version_id
                       AND NEW.archived_at IS NOT DISTINCT FROM OLD.archived_at THEN
                        RETURN NEW;
                    END IF;
                    RAISE EXCEPTION 'Scheduled Gacha Version is immutable';
                END IF;

                IF TG_TABLE_NAME = 'catalog_gacha_tags' THEN
                    parent_gacha_id := CASE
                        WHEN TG_OP = 'DELETE' THEN OLD.gacha_id
                        ELSE NEW.gacha_id
                    END;
                ELSE
                    SELECT gacha_id INTO parent_gacha_id
                    FROM catalog_gacha_versions
                    WHERE id = CASE
                        WHEN TG_OP = 'DELETE' THEN OLD.gacha_version_id
                        ELSE NEW.gacha_version_id
                    END;
                END IF;
                IF EXISTS (
                    SELECT 1
                    FROM catalog_gacha_publish_schedules
                    WHERE gacha_id = parent_gacha_id
                      AND status IN ('scheduled', 'processing')
                ) THEN
                    RAISE EXCEPTION 'Scheduled Gacha relations are immutable';
                END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER catalog_gachas_schedule_guard '.
            'BEFORE UPDATE ON catalog_gachas FOR EACH ROW '.
            'EXECUTE FUNCTION v2_catalog_scheduled_draft_guard()'
        );
        DB::statement(
            'CREATE TRIGGER catalog_gacha_versions_schedule_guard '.
            'BEFORE UPDATE ON catalog_gacha_versions FOR EACH ROW '.
            'EXECUTE FUNCTION v2_catalog_scheduled_draft_guard()'
        );
        DB::statement(
            'CREATE TRIGGER catalog_gacha_version_prizes_schedule_guard '.
            'BEFORE INSERT OR UPDATE OR DELETE ON catalog_gacha_version_prizes '.
            'FOR EACH ROW EXECUTE FUNCTION v2_catalog_scheduled_draft_guard()'
        );
        DB::statement(
            'CREATE TRIGGER catalog_gacha_tags_schedule_guard '.
            'BEFORE INSERT OR UPDATE OR DELETE ON catalog_gacha_tags '.
            'FOR EACH ROW EXECUTE FUNCTION v2_catalog_scheduled_draft_guard()'
        );
    }
};
