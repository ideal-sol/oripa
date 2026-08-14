<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('catalog_gacha_publish_schedules')
            ->whereIn('status', ['scheduled', 'processing'])->exists()) {
            throw new RuntimeException(
                'Active legacy Gacha Publish Schedules must be resolved before MIG-062Q.'
            );
        }
        if (DB::table('gacha_draw_states')
            ->select('gacha_id')->whereIn('status', ['selling', 'paused'])
            ->groupBy('gacha_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException(
                'Multiple open Draw States must be resolved before MIG-062Q.'
            );
        }
        if (DB::table('gacha_draw_states as state')
            ->leftJoin('catalog_gachas as gacha', function ($join): void {
                $join->on('gacha.id', '=', 'state.gacha_id')
                    ->on('gacha.active_draw_state_id', '=', 'state.id');
            })
            ->whereIn('state.status', ['selling', 'paused'])
            ->whereNull('gacha.id')
            ->exists()) {
            throw new RuntimeException(
                'A non-active open Draw State must be resolved before MIG-062Q.'
            );
        }

        Schema::table('catalog_gachas', function (Blueprint $table): void {
            $table->timestampTz('first_published_at')->nullable()
                ->after('management_status');
            $table->timestampTz('scheduled_start_at')->nullable()
                ->after('first_published_at');
            $table->string('current_title', 191)->nullable()
                ->after('scheduled_start_at');
            $table->text('current_description')->nullable()
                ->after('current_title');
            $table->text('current_notices')->nullable()
                ->after('current_description');
            $table->foreignId('current_presentation_asset_id')->nullable()
                ->after('current_notices')
                ->constrained('catalog_presentation_assets')->restrictOnDelete();
            $table->timestampTz('current_publish_end_at')->nullable()
                ->after('current_presentation_asset_id');
            $table->index('first_published_at');
        });
        DB::statement(
            'ALTER TABLE catalog_gachas '.
            'ADD COLUMN current_publish_start_at TIMESTAMPTZ NULL'
        );
        Schema::table('gacha_draw_states', function (Blueprint $table): void {
            $table->timestampTz('closed_at')->nullable()->after('sold_out_at');
            $table->string('close_reason', 32)->nullable()->after('closed_at');
        });

        DB::statement(<<<'SQL'
            UPDATE catalog_gachas AS g
            SET first_published_at = CASE
                    WHEN g.management_status = 'scheduled' AND version.id IS NOT NULL
                        THEN version.publish_start_at
                    ELSE source.first_published_at
                END,
                scheduled_start_at = CASE
                    WHEN g.management_status = 'scheduled' AND version.id IS NOT NULL
                        THEN version.publish_start_at
                    ELSE NULL
                END,
                current_publish_start_at = version.publish_start_at,
                current_title = version.title,
                current_description = version.description,
                current_notices = version.notices,
                current_presentation_asset_id = version.presentation_asset_id,
                current_publish_end_at = version.publish_end_at,
                revision = g.revision + 1,
                updated_at = CURRENT_TIMESTAMP
            FROM (
                SELECT candidate.id AS gacha_id,
                    candidate.published_version_id,
                    MIN(history.published_at) AS first_published_at
                FROM catalog_gachas AS candidate
                LEFT JOIN catalog_gacha_versions AS history
                    ON history.gacha_id = candidate.id
                    AND history.published_at IS NOT NULL
                WHERE candidate.published_version_id IS NOT NULL
                    OR history.id IS NOT NULL
                GROUP BY candidate.id, candidate.published_version_id
            ) AS source
            LEFT JOIN catalog_gacha_versions AS version
                ON version.id = source.published_version_id
            WHERE source.gacha_id = g.id
            SQL);

        DB::statement(
            'ALTER TABLE gacha_draw_states DROP CONSTRAINT gacha_draw_state_values_check'
        );
        DB::statement(<<<'SQL'
            ALTER TABLE gacha_draw_states
            ADD CONSTRAINT gacha_draw_state_values_check CHECK (
                total_count > 0
                AND sold_count <= total_count
                AND CASE status::text
                    WHEN 'sold_out' THEN
                        sold_count = total_count
                        AND sold_out_at IS NOT NULL
                        AND closed_at IS NULL
                        AND close_reason IS NULL
                    WHEN 'closed' THEN
                        sold_out_at IS NULL
                        AND closed_at IS NOT NULL
                        AND close_reason::text = ANY (ARRAY[
                            'schedule_cancelled'::text,
                            'superseded'::text
                        ])
                    WHEN 'selling' THEN
                        sold_out_at IS NULL
                        AND closed_at IS NULL
                        AND close_reason IS NULL
                    WHEN 'paused' THEN
                        sold_out_at IS NULL
                        AND closed_at IS NULL
                        AND close_reason IS NULL
                    ELSE FALSE
                END
            )
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX gacha_draw_states_one_open_per_gacha
            ON gacha_draw_states (gacha_id)
            WHERE status::text = ANY (ARRAY['selling'::text, 'paused'::text])
            SQL);
        $this->installSalesStateGuard(true);
        $this->installGachaRelationGuard(true);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_gacha_lifecycle_guard()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF OLD.management_status = 'unpublished'
                   AND NEW.management_status <> 'unpublished' THEN
                    RAISE EXCEPTION 'Unpublished Gacha lifecycle is terminal';
                END IF;

                IF OLD.first_published_at IS NOT NULL
                   AND OLD.first_published_at <= CURRENT_TIMESTAMP
                   AND NEW.first_published_at IS DISTINCT FROM OLD.first_published_at THEN
                    RAISE EXCEPTION 'First publication history is immutable';
                END IF;

                IF OLD.first_published_at IS NOT NULL
                   AND OLD.first_published_at > CURRENT_TIMESTAMP
                   AND NEW.first_published_at IS DISTINCT FROM OLD.first_published_at
                   AND NOT (
                       OLD.management_status = 'scheduled'
                       AND (
                           NEW.management_status = 'scheduled'
                           AND NEW.first_published_at > CURRENT_TIMESTAMP
                           OR NEW.management_status = 'draft'
                           AND NEW.first_published_at IS NULL
                       )
                   ) THEN
                    RAISE EXCEPTION 'Scheduled first publication can only be changed before start';
                END IF;

                IF NEW.management_status = 'scheduled'
                   AND (
                       NEW.first_published_at IS NULL
                       OR NEW.first_published_at <= CURRENT_TIMESTAMP
                       OR NEW.scheduled_start_at IS DISTINCT FROM NEW.first_published_at
                       OR NEW.published_version_id IS NULL
                       OR NEW.active_draw_state_id IS NULL
                   ) THEN
                    RAISE EXCEPTION 'Scheduled Gacha requires a future first publication';
                END IF;

                IF OLD.first_published_at IS NOT NULL
                   AND OLD.first_published_at <= CURRENT_TIMESTAMP
                   AND NEW.management_status = 'scheduled' THEN
                    RAISE EXCEPTION 'Published Gacha cannot be scheduled again';
                END IF;

                IF NEW.management_status <> 'scheduled'
                   AND NEW.scheduled_start_at IS NOT NULL THEN
                    RAISE EXCEPTION 'Only a scheduled Gacha may retain a scheduled start';
                END IF;

                RETURN NEW;
            END;
            $$
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER catalog_gachas_lifecycle_guard
            BEFORE UPDATE OF management_status, first_published_at,
                scheduled_start_at, published_version_id, active_draw_state_id
            ON catalog_gachas FOR EACH ROW
            EXECUTE FUNCTION v2_catalog_gacha_lifecycle_guard()
            SQL);
    }

    public function down(): void
    {
        if (DB::table('gacha_draw_states')->where('status', 'closed')->exists()) {
            throw new RuntimeException(
                'Cannot roll back MIG-062Q after a scheduled Draw State was closed.'
            );
        }

        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_gachas_lifecycle_guard ON catalog_gachas'
        );
        DB::statement('DROP FUNCTION IF EXISTS v2_catalog_gacha_lifecycle_guard()');
        DB::statement('DROP INDEX IF EXISTS gacha_draw_states_one_open_per_gacha');
        $this->installSalesStateGuard(false);
        $this->installGachaRelationGuard(false);
        DB::statement(
            'ALTER TABLE gacha_draw_states DROP CONSTRAINT gacha_draw_state_values_check'
        );
        DB::statement(<<<'SQL'
            ALTER TABLE gacha_draw_states
            ADD CONSTRAINT gacha_draw_state_values_check CHECK (
                status::text = ANY (ARRAY[
                    'selling'::text,
                    'paused'::text,
                    'sold_out'::text
                ])
                AND total_count > 0
                AND sold_count <= total_count
                AND (
                    status = 'sold_out'
                    AND sold_count = total_count
                    AND sold_out_at IS NOT NULL
                    OR status <> 'sold_out'
                    AND sold_out_at IS NULL
                )
            )
            SQL);
        Schema::table('gacha_draw_states', function (Blueprint $table): void {
            $table->dropColumn(['closed_at', 'close_reason']);
        });
        Schema::table('catalog_gachas', function (Blueprint $table): void {
            $table->dropIndex(['first_published_at']);
            $table->dropForeign(['current_presentation_asset_id']);
            $table->dropColumn([
                'first_published_at',
                'scheduled_start_at',
                'current_publish_start_at',
                'current_title',
                'current_description',
                'current_notices',
                'current_presentation_asset_id',
                'current_publish_end_at',
            ]);
        });
    }

    private function installSalesStateGuard(bool $useCurrentPresentation): void
    {
        $startGuard = $useCurrentPresentation
            ? 'COALESCE(NEW.current_publish_start_at, version_row.publish_start_at) > CURRENT_TIMESTAMP'
            : 'version_row.publish_start_at > CURRENT_TIMESTAMP';
        $endGuard = $useCurrentPresentation
            ? '(NEW.current_publish_end_at IS NOT NULL AND NEW.current_publish_end_at <= CURRENT_TIMESTAMP)'
            : '(version_row.publish_end_at IS NOT NULL AND version_row.publish_end_at <= CURRENT_TIMESTAMP)';
        $sql = str_replace(
            ['__START_GUARD__', '__END_GUARD__'],
            [$startGuard, $endGuard],
            <<<'SQL'
                CREATE OR REPLACE FUNCTION v2_catalog_gacha_sales_state_guard()
                RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                DECLARE version_row catalog_gacha_versions%ROWTYPE;
                DECLARE state_row gacha_draw_states%ROWTYPE;
                DECLARE gacha_row catalog_gachas%ROWTYPE;
                DECLARE probability_row catalog_probability_versions%ROWTYPE;
                BEGIN
                    IF TG_TABLE_NAME = 'draw_requests' THEN
                        SELECT g.* INTO gacha_row
                        FROM catalog_gachas AS g
                        INNER JOIN gacha_draw_states AS state
                            ON state.gacha_id = g.id
                        WHERE state.id = NEW.gacha_draw_state_id;
                        IF gacha_row.id IS NULL OR gacha_row.sales_paused = TRUE THEN
                            RAISE EXCEPTION 'Paused Gacha cannot accept a new Draw Request';
                        END IF;
                        RETURN NEW;
                    END IF;

                    IF TG_OP = 'DELETE' THEN
                        IF OLD.sales_paused_at IS NOT NULL THEN
                            RAISE EXCEPTION 'Gacha Sales operation history cannot be deleted';
                        END IF;
                        RETURN OLD;
                    END IF;

                    IF (
                        NEW.sales_paused IS DISTINCT FROM OLD.sales_paused
                        OR NEW.sales_paused_at IS DISTINCT FROM OLD.sales_paused_at
                        OR NEW.sales_paused_by_admin_public_id
                            IS DISTINCT FROM OLD.sales_paused_by_admin_public_id
                        OR NEW.sales_pause_reason_code
                            IS DISTINCT FROM OLD.sales_pause_reason_code
                        OR NEW.sales_resumed_at IS DISTINCT FROM OLD.sales_resumed_at
                        OR NEW.sales_last_mutation_request_id
                            IS DISTINCT FROM OLD.sales_last_mutation_request_id
                    ) THEN
                        IF NEW.sales_paused IS NOT DISTINCT FROM OLD.sales_paused
                           OR NEW.revision IS DISTINCT FROM OLD.revision + 1
                           OR NEW.public_id IS DISTINCT FROM OLD.public_id
                           OR NEW.code IS DISTINCT FROM OLD.code
                           OR NEW.slug IS DISTINCT FROM OLD.slug
                           OR NEW.category_id IS DISTINCT FROM OLD.category_id
                           OR NEW.state IS DISTINCT FROM OLD.state
                           OR NEW.sold_count IS DISTINCT FROM OLD.sold_count
                           OR NEW.published_version_id
                                IS DISTINCT FROM OLD.published_version_id
                           OR NEW.active_draw_state_id
                                IS DISTINCT FROM OLD.active_draw_state_id
                           OR NEW.archived_at IS DISTINCT FROM OLD.archived_at THEN
                            RAISE EXCEPTION
                                'Gacha Sales state requires one Revision transition';
                        END IF;
                        IF NEW.published_version_id IS NULL
                           OR NEW.active_draw_state_id IS NULL
                           OR NEW.archived_at IS NOT NULL
                           OR NEW.state::text <> 'active'::text THEN
                            RAISE EXCEPTION
                                'Gacha Sales state requires an active Published Gacha';
                        END IF;

                        SELECT * INTO version_row
                        FROM catalog_gacha_versions
                        WHERE id = NEW.published_version_id;
                        SELECT * INTO state_row
                        FROM gacha_draw_states
                        WHERE id = NEW.active_draw_state_id;
                        SELECT * INTO probability_row
                        FROM catalog_probability_versions
                        WHERE id = version_row.published_probability_version_id;
                        IF version_row.id IS NULL
                           OR version_row.gacha_id IS DISTINCT FROM NEW.id
                           OR version_row.status::text <> 'published'::text
                           OR version_row.archived_at IS NOT NULL
                           OR version_row.published_at IS NULL
                           OR state_row.id IS NULL
                           OR state_row.gacha_id IS DISTINCT FROM NEW.id
                           OR state_row.gacha_version_id IS DISTINCT FROM version_row.id
                           OR state_row.probability_version_id
                                IS DISTINCT FROM probability_row.id
                           OR state_row.status::text <> 'selling'::text
                           OR probability_row.status::text <> 'published'::text
                           OR probability_row.archived_at IS NOT NULL
                           OR probability_row.snapshot_sha256 !~ '^[0-9a-f]{64}$' THEN
                            RAISE EXCEPTION
                                'Gacha Sales state requires matching active Draw references';
                        END IF;
                        IF NEW.sales_paused = FALSE THEN
                            IF state_row.sold_count >= state_row.total_count
                               OR __START_GUARD__
                               OR __END_GUARD__
                               OR EXISTS (
                                   SELECT 1
                                   FROM catalog_gacha_publish_schedules
                                   WHERE gacha_id = NEW.id
                                     AND status::text = 'processing'::text
                               ) THEN
                                RAISE EXCEPTION 'Gacha Sales Resume preflight failed';
                            END IF;
                        END IF;
                    END IF;
                    RETURN NEW;
                END;
                $$
                SQL
        );
        DB::unprepared($sql);
    }

    private function installGachaRelationGuard(bool $allowCurrentTags): void
    {
        $tagGuard = $allowCurrentTags
            ? <<<'SQL'
                    IF parent_archived_at IS NOT NULL THEN
                        RAISE EXCEPTION 'Archived Gacha relations are immutable';
                    END IF;
                SQL
            : <<<'SQL'
                    SELECT EXISTS (
                        SELECT 1
                        FROM catalog_gacha_versions
                        WHERE gacha_id = parent_id
                          AND status = 'published'
                    ) INTO has_published_version;
                    SELECT EXISTS (
                        SELECT 1
                        FROM draw_requests AS draw
                        INNER JOIN gacha_draw_states AS state
                            ON state.id = draw.gacha_draw_state_id
                        WHERE state.gacha_id = parent_id
                    ) INTO has_draw_history;
                    IF parent_archived_at IS NOT NULL
                       OR has_published_version
                       OR has_draw_history THEN
                        RAISE EXCEPTION
                            'Published or drawn Gacha references protect this relation';
                    END IF;
                SQL;
        DB::unprepared(str_replace(
            '__TAG_GUARD__',
            $tagGuard,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION v2_catalog_protect_gacha_draft_relation()
                RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                DECLARE parent_id bigint;
                DECLARE parent_archived_at timestamptz;
                DECLARE has_published_version boolean := false;
                DECLARE has_draw_history boolean := false;
                BEGIN
                    IF TG_TABLE_NAME = 'catalog_gacha_tags' THEN
                        parent_id := CASE
                            WHEN TG_OP = 'DELETE' THEN OLD.gacha_id
                            ELSE NEW.gacha_id
                        END;
                        SELECT archived_at INTO parent_archived_at
                        FROM catalog_gachas
                        WHERE id = parent_id;
                __TAG_GUARD__
                    ELSE
                        parent_id := CASE
                            WHEN TG_OP = 'DELETE' THEN OLD.gacha_version_id
                            ELSE NEW.gacha_version_id
                        END;
                        SELECT archived_at INTO parent_archived_at
                        FROM catalog_gacha_versions
                        WHERE id = parent_id;
                        IF parent_archived_at IS NOT NULL THEN
                            RAISE EXCEPTION
                                'Archived Gacha Draft Version relations are immutable';
                        END IF;
                    END IF;

                    IF TG_OP = 'DELETE' THEN
                        RETURN OLD;
                    END IF;
                    RETURN NEW;
                END;
                $$
                SQL
        ));
    }
};
