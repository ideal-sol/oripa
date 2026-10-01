<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_gachas', function (Blueprint $table): void {
            $table->string('gacha_type', 24)->default('standard');
        });
        Schema::table('catalog_gacha_versions', function (Blueprint $table): void {
            $table->bigInteger('minimum_exchange_points')->nullable();
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('first_registration_qualified_at')->nullable();
        });
        Schema::table('catalog_probability_entries', function (Blueprint $table): void {
            $table->bigInteger('rate_units')->nullable();
        });
        Schema::table('catalog_gacha_ranks', function (Blueprint $table): void {
            $table->foreignId('preferred_rank_revision_id')->nullable()
                ->constrained('catalog_rank_master_revisions')->restrictOnDelete();
        });
        Schema::table('catalog_gacha_version_prizes', function (Blueprint $table): void {
            $table->foreignId('published_rank_revision_id')->nullable()
                ->constrained('catalog_rank_master_revisions')->restrictOnDelete();
            $table->foreignId('published_video_revision_id')->nullable()
                ->constrained('catalog_gacha_rank_video_revisions')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE catalog_gachas ALTER COLUMN category_id DROP NOT NULL;
            ALTER TABLE catalog_gacha_versions ALTER COLUMN total_count DROP NOT NULL;
            ALTER TABLE gacha_draw_states ALTER COLUMN total_count DROP NOT NULL;
            ALTER TABLE catalog_probability_entries ALTER COLUMN probability_ppm DROP NOT NULL;
            ALTER TABLE draw_results ALTER COLUMN random_value TYPE bigint;
            ALTER TABLE catalog_gachas ADD CONSTRAINT catalog_gacha_type_check
                CHECK (gacha_type::text IN ('standard', 'login_daily', 'signup_once'));
            ALTER TABLE catalog_gacha_versions DROP CONSTRAINT catalog_gacha_version_values_check;
            ALTER TABLE catalog_gacha_versions ADD CONSTRAINT catalog_gacha_version_values_check CHECK (
                version_number > 0 AND status::text IN ('draft', 'published') AND price_points >= 0
                AND (minimum_exchange_points IS NULL OR minimum_exchange_points >= 0)
                AND (total_count IS NULL OR total_count > 0)
                AND (publish_end_at IS NULL OR publish_end_at > publish_start_at)
            );
            ALTER TABLE catalog_probability_entries DROP CONSTRAINT catalog_probability_entry_values_check;
            ALTER TABLE catalog_probability_entries ADD CONSTRAINT catalog_probability_entry_values_check CHECK (
                result_type::text IN ('prize', 'point_back')
                AND (
                    (probability_ppm IS NOT NULL AND probability_ppm BETWEEN 0 AND 1000000 AND rate_units IS NULL)
                    OR (probability_ppm IS NULL AND rate_units IS NOT NULL AND rate_units BETWEEN 1 AND 1000000000000)
                )
                AND ((result_type = 'prize' AND gacha_version_prize_id IS NOT NULL AND point_amount IS NULL)
                    OR (result_type = 'point_back' AND gacha_version_prize_id IS NULL AND point_amount IS NOT NULL AND point_amount >= 0))
            );
            ALTER TABLE gacha_draw_states DROP CONSTRAINT gacha_draw_state_values_check;
            ALTER TABLE gacha_draw_states ADD CONSTRAINT gacha_draw_state_values_check CHECK (
                (total_count IS NULL OR total_count > 0) AND sold_count >= 0
                AND CASE status
                    WHEN 'sold_out' THEN sold_out_at IS NOT NULL AND closed_at IS NULL AND close_reason IS NULL
                    WHEN 'closed' THEN sold_out_at IS NULL AND closed_at IS NOT NULL
                        AND close_reason::text IN ('schedule_cancelled', 'superseded')
                    WHEN 'selling' THEN sold_out_at IS NULL AND closed_at IS NULL AND close_reason IS NULL
                    WHEN 'paused' THEN sold_out_at IS NULL AND closed_at IS NULL AND close_reason IS NULL
                    ELSE FALSE END
            );
            ALTER TABLE draw_requests DROP CONSTRAINT draw_request_values_check;
            ALTER TABLE draw_requests ADD CONSTRAINT draw_request_values_check CHECK (
                requested_count = ANY (ARRAY[1,5,10,100,1000]) AND executed_count <= requested_count
                AND point_cost_total >= 0 AND consumed_paid_points >= 0 AND consumed_free_points >= 0
                AND point_back_total >= 0 AND request_hash ~ '^[0-9a-f]{64}$'
                AND catalog_snapshot_sha256 ~ '^[0-9a-f]{64}$' AND status::text IN ('processing', 'completed')
                AND ((status = 'completed' AND executed_count > 0 AND executed_count <= requested_count
                    AND wallet_paid_after IS NOT NULL AND wallet_free_after IS NOT NULL
                    AND processing_duration_ms IS NOT NULL AND response_data IS NOT NULL AND completed_at IS NOT NULL)
                    OR (status = 'processing' AND executed_count = 0 AND completed_at IS NULL))
            );
            ALTER TABLE draw_results DROP CONSTRAINT draw_result_values_check;
            ALTER TABLE draw_results ADD CONSTRAINT draw_result_values_check CHECK (
                request_sequence > 0 AND draw_sequence_number > 0 AND consumed_points >= 0
                AND point_back_amount >= 0 AND random_value BETWEEN 0 AND 999999999999
                AND display_snapshot_sha256 ~ '^[0-9a-f]{64}$' AND jsonb_typeof(display_snapshot) = 'object'
                AND ((result_type = 'prize' AND gacha_version_prize_id IS NOT NULL AND point_back_amount = 0
                    AND ((rank_id IS NOT NULL AND rank_master_revision_id IS NULL AND gacha_rank_video_revision_id IS NULL)
                        OR (rank_id IS NULL AND rank_master_revision_id IS NOT NULL AND gacha_rank_video_revision_id IS NOT NULL)))
                    OR (result_type = 'point_back' AND gacha_version_prize_id IS NULL AND rank_id IS NULL
                        AND rank_master_revision_id IS NULL AND gacha_rank_video_revision_id IS NULL AND point_back_amount >= 0))
            );
        SQL);
        $this->registrationTimestamp();
        $this->typedGuards();
        $this->ownershipGuards();
        $this->capacityGuard();
        $this->salesStateGuard();
        $this->scheduleGuard();
        $this->scheduledPresentationGuard();
        $this->probabilityGuards();
        $this->immutabilityGuards();
    }

    public function down(): void
    {
        throw new LogicException('Login eligibility, rates and Draw history require a forward correction migration.');
    }

    private function registrationTimestamp(): void
    {
        $this->backfillRegistrationTimestamp();
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_guard_registration_qualification() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.first_registration_qualified_at IS NOT NULL
                   AND NEW.first_registration_qualified_at IS DISTINCT FROM OLD.first_registration_qualified_at THEN
                    RAISE EXCEPTION 'First registration qualification is immutable';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER users_registration_qualification_guard BEFORE UPDATE ON users
                FOR EACH ROW EXECUTE FUNCTION v2_guard_registration_qualification();
        SQL);
    }

    private function backfillRegistrationTimestamp(): void
    {
        DB::unprepared(<<<'SQL'
            WITH evidence AS (
                SELECT user_id, MIN(used_at) AS qualified_at
                FROM user_email_verifications WHERE used_at IS NOT NULL GROUP BY user_id
                UNION ALL
                SELECT account.id, account.created_at
                FROM users account
                WHERE EXISTS (
                    SELECT 1 FROM outbox_messages event
                    WHERE event.aggregate_type = 'user' AND event.aggregate_public_id = account.public_id
                      AND event.event_type = 'identity.external_user.created'
                )
            ), first_evidence AS (
                SELECT user_id, MIN(qualified_at) AS qualified_at FROM evidence GROUP BY user_id
            )
            UPDATE users SET first_registration_qualified_at = first_evidence.qualified_at
            FROM first_evidence WHERE users.id = first_evidence.user_id
              AND users.first_registration_qualified_at IS NULL;
        SQL);
    }

    private function typedGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_guard_gacha_type() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'UPDATE' AND NEW.gacha_type IS DISTINCT FROM OLD.gacha_type THEN
                    RAISE EXCEPTION 'Canonical Gacha type is immutable';
                END IF;
                IF (NEW.gacha_type = 'standard' AND NEW.category_id IS NULL)
                   OR (NEW.gacha_type IN ('login_daily', 'signup_once') AND NEW.category_id IS NOT NULL) THEN
                    RAISE EXCEPTION 'Gacha category must match its Canonical type';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER catalog_gachas_type_guard BEFORE INSERT OR UPDATE ON catalog_gachas
                FOR EACH ROW EXECUTE FUNCTION v2_catalog_guard_gacha_type();

            CREATE OR REPLACE FUNCTION v2_catalog_guard_typed_version() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE kind text;
            DECLARE first_publication timestamptz;
            BEGIN
                SELECT gacha_type, first_published_at INTO STRICT kind, first_publication FROM catalog_gachas WHERE id = NEW.gacha_id;
                IF kind = 'standard' THEN
                    IF NEW.total_count IS NULL OR NEW.total_count <= 0 OR NEW.price_points <= 0
                       OR NEW.minimum_exchange_points IS NOT NULL THEN
                        RAISE EXCEPTION 'Standard Gacha requires positive price and total count';
                    END IF;
                ELSIF kind IN ('login_daily', 'signup_once') THEN
                    IF first_publication IS NOT NULL THEN
                        RAISE EXCEPTION 'Published login Gacha versions are immutable';
                    END IF;
                    IF NEW.total_count IS NOT NULL OR NEW.category_id IS NOT NULL OR NEW.price_points < 0
                       OR NEW.allowed_draw_counts IS DISTINCT FROM '[1]'::jsonb
                       OR NEW.audience_code <> 'all_users' OR NEW.first_time_eligible_days <> 7
                       OR (kind = 'login_daily' AND (NEW.daily_draw_limit <> 1 OR NEW.minimum_exchange_points IS NULL))
                       OR (kind = 'signup_once' AND (NEW.price_points <> 0 OR NEW.daily_draw_limit <> 0 OR NEW.minimum_exchange_points IS NOT NULL)) THEN
                        RAISE EXCEPTION 'Login Gacha economics do not match its Canonical type';
                    END IF;
                    IF NEW.status = 'published' AND EXISTS (
                        SELECT 1 FROM catalog_gacha_version_prizes relation
                        WHERE relation.gacha_version_id = NEW.id
                          AND (relation.published_rank_revision_id IS NULL OR relation.published_video_revision_id IS NULL)
                    ) THEN
                        RAISE EXCEPTION 'Published login Gacha requires pinned presentation revisions';
                    END IF;
                ELSE
                    RAISE EXCEPTION 'Unknown Canonical Gacha type';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER catalog_gacha_versions_type_guard BEFORE INSERT OR UPDATE ON catalog_gacha_versions
                FOR EACH ROW EXECUTE FUNCTION v2_catalog_guard_typed_version();

            CREATE OR REPLACE FUNCTION v2_catalog_guard_pinned_rank() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE kind text;
            BEGIN
                SELECT gacha.gacha_type INTO STRICT kind FROM catalog_gacha_versions version
                JOIN catalog_gachas gacha ON gacha.id = version.gacha_id WHERE version.id = NEW.gacha_version_id;
                IF kind = 'standard' THEN
                    IF NEW.published_rank_revision_id IS NOT NULL OR NEW.published_video_revision_id IS NOT NULL THEN
                        RAISE EXCEPTION 'Standard Gacha retains current Rank presentation';
                    END IF;
                ELSE
                    IF (NEW.published_rank_revision_id IS NULL) <> (NEW.published_video_revision_id IS NULL)
                       OR (NEW.published_rank_revision_id IS NOT NULL AND NOT EXISTS (
                            SELECT 1 FROM catalog_gacha_ranks rank
                            JOIN catalog_rank_master_revisions revision ON revision.rank_master_id = rank.rank_master_id
                            JOIN catalog_gacha_rank_video_revisions video ON video.gacha_rank_id = rank.id
                            WHERE rank.id = NEW.gacha_rank_id AND revision.id = NEW.published_rank_revision_id
                              AND video.id = NEW.published_video_revision_id
                       )) THEN
                        RAISE EXCEPTION 'Pinned presentation must belong to the Prize Canonical Rank';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER catalog_gacha_version_prizes_pinned_rank_guard BEFORE INSERT OR UPDATE ON catalog_gacha_version_prizes
                FOR EACH ROW EXECUTE FUNCTION v2_catalog_guard_pinned_rank();

            CREATE OR REPLACE FUNCTION v2_draw_guard_gacha_type() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE kind text;
            DECLARE version_row catalog_gacha_versions%ROWTYPE;
            DECLARE version_id bigint;
            BEGIN
                IF TG_TABLE_NAME = 'draw_results' THEN
                    SELECT gacha_version_id INTO version_id FROM draw_requests WHERE id = NEW.draw_request_id;
                ELSE
                    version_id := NEW.gacha_version_id;
                END IF;
                SELECT * INTO STRICT version_row FROM catalog_gacha_versions WHERE id = version_id;
                SELECT gacha_type INTO STRICT kind FROM catalog_gachas WHERE id = version_row.gacha_id;
                IF TG_TABLE_NAME = 'gacha_draw_states' THEN
                    IF NEW.gacha_id <> version_row.gacha_id OR NEW.total_count IS DISTINCT FROM version_row.total_count
                       OR (kind = 'standard' AND NEW.total_count IS NULL)
                       OR (kind <> 'standard' AND (NEW.total_count IS NOT NULL OR NEW.status = 'sold_out')) THEN
                        RAISE EXCEPTION 'Draw state capacity must match Canonical Gacha type';
                    END IF;
                ELSIF TG_TABLE_NAME = 'draw_requests' THEN
                    IF kind = 'standard' AND NEW.point_cost_total <= 0 THEN
                        RAISE EXCEPTION 'Standard Draw must consume positive points';
                    ELSIF kind <> 'standard' AND (NEW.requested_count <> 1 OR NEW.is_qa_draw
                        OR NEW.point_cost_total <> version_row.price_points
                        OR NEW.point_back_total <> 0) THEN
                        RAISE EXCEPTION 'Login Draw is single and cannot use QA';
                    END IF;
                ELSE
                    IF kind = 'standard' AND (NEW.consumed_points <= 0 OR NEW.random_value > 999999) THEN
                        RAISE EXCEPTION 'Standard Draw random value and cost retain existing semantics';
                    ELSIF kind <> 'standard' AND (NEW.consumed_points <> version_row.price_points
                        OR NEW.result_type <> 'prize' OR NEW.request_sequence <> 1) THEN
                        RAISE EXCEPTION 'Login Draw result must match its economic snapshot';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER gacha_draw_states_type_guard BEFORE INSERT OR UPDATE ON gacha_draw_states
                FOR EACH ROW EXECUTE FUNCTION v2_draw_guard_gacha_type();
            CREATE TRIGGER draw_requests_type_guard BEFORE INSERT OR UPDATE ON draw_requests
                FOR EACH ROW EXECUTE FUNCTION v2_draw_guard_gacha_type();
            CREATE TRIGGER draw_results_type_guard BEFORE INSERT ON draw_results
                FOR EACH ROW EXECUTE FUNCTION v2_draw_guard_gacha_type();
        SQL);
    }

    private function ownershipGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_login_owner(table_name text, row_data jsonb) RETURNS bigint LANGUAGE plpgsql AS $$
            DECLARE owner_id bigint;
            BEGIN
                IF table_name IN ('catalog_gacha_versions', 'catalog_prizes', 'catalog_gacha_ranks', 'catalog_gacha_tags') THEN
                    owner_id := (row_data->>'gacha_id')::bigint;
                ELSIF table_name IN ('catalog_gacha_version_prizes', 'catalog_probability_versions', 'catalog_gacha_version_tags') THEN
                    SELECT gacha_id INTO owner_id FROM catalog_gacha_versions WHERE id = (row_data->>'gacha_version_id')::bigint;
                ELSIF table_name = 'catalog_gacha_rank_video_revisions' THEN
                    SELECT gacha_id INTO owner_id FROM catalog_gacha_ranks WHERE id = (row_data->>'gacha_rank_id')::bigint;
                ELSIF table_name = 'prize_inventories' THEN
                    SELECT version.gacha_id INTO owner_id FROM catalog_gacha_version_prizes relation
                    JOIN catalog_gacha_versions version ON version.id = relation.gacha_version_id
                    WHERE relation.id = (row_data->>'gacha_version_prize_id')::bigint;
                ELSIF table_name = 'catalog_probability_stages' THEN
                    SELECT version.gacha_id INTO owner_id FROM catalog_probability_versions probability
                    JOIN catalog_gacha_versions version ON version.id = probability.gacha_version_id
                    WHERE probability.id = (row_data->>'probability_version_id')::bigint;
                ELSE
                    SELECT version.gacha_id INTO owner_id FROM catalog_probability_stages stage
                    JOIN catalog_probability_versions probability ON probability.id = stage.probability_version_id
                    JOIN catalog_gacha_versions version ON version.id = probability.gacha_version_id
                    WHERE stage.id = (row_data->>'probability_stage_id')::bigint;
                END IF;
                RETURN owner_id;
            END;
            $$;
            CREATE OR REPLACE FUNCTION v2_login_guard_ownership() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF to_jsonb(OLD)->TG_ARGV[0] IS DISTINCT FROM to_jsonb(NEW)->TG_ARGV[0]
                   AND EXISTS (SELECT 1 FROM catalog_gachas WHERE gacha_type <> 'standard' AND id IN (
                        v2_login_owner(TG_TABLE_NAME, to_jsonb(OLD)), v2_login_owner(TG_TABLE_NAME, to_jsonb(NEW))
                   )) THEN
                    RAISE EXCEPTION 'Login composition ownership is immutable';
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);
        foreach ([
            'catalog_gacha_versions' => 'gacha_id', 'catalog_prizes' => 'gacha_id', 'catalog_gacha_ranks' => 'gacha_id',
            'catalog_gacha_tags' => 'gacha_id', 'catalog_gacha_version_prizes' => 'gacha_version_id',
            'catalog_probability_versions' => 'gacha_version_id', 'catalog_gacha_version_tags' => 'gacha_version_id',
            'catalog_gacha_rank_video_revisions' => 'gacha_rank_id', 'prize_inventories' => 'gacha_version_prize_id',
            'catalog_probability_stages' => 'probability_version_id', 'catalog_probability_entries' => 'probability_stage_id',
            'catalog_minimum_guarantees' => 'probability_stage_id',
        ] as $tableName => $parentField) {
            DB::statement("CREATE TRIGGER {$tableName}_login_owner_guard BEFORE UPDATE ON {$tableName} ".
                "FOR EACH ROW EXECUTE FUNCTION v2_login_guard_ownership('{$parentField}')");
        }
    }

    private function capacityGuard(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_validate_gacha_inventory_capacity()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE version_id bigint;
            DECLARE capacity bigint;
            DECLARE kind text;
            DECLARE snapshot_total numeric;
            DECLARE operational_total numeric;
            BEGIN
                IF TG_TABLE_NAME = 'catalog_gacha_versions' THEN version_id := NEW.id;
                ELSIF TG_TABLE_NAME = 'catalog_gacha_version_prizes' THEN version_id := NEW.gacha_version_id;
                ELSE SELECT gacha_version_id INTO version_id FROM catalog_gacha_version_prizes WHERE id = NEW.gacha_version_prize_id;
                END IF;
                IF version_id IS NULL THEN RETURN NULL; END IF;
                SELECT version.total_count, gacha.gacha_type INTO capacity, kind
                FROM catalog_gacha_versions version JOIN catalog_gachas gacha ON gacha.id = version.gacha_id
                WHERE version.id = version_id FOR UPDATE OF version;
                IF kind IN ('login_daily', 'signup_once') THEN
                    IF capacity IS NOT NULL THEN RAISE EXCEPTION 'Login Gacha has no total count'; END IF;
                    RETURN NULL;
                END IF;
                IF kind IS DISTINCT FROM 'standard' OR capacity IS NULL OR capacity <= 0 THEN
                    RAISE EXCEPTION 'Standard Gacha requires positive capacity';
                END IF;
                SELECT COALESCE(SUM(initial_inventory), 0) INTO snapshot_total
                FROM catalog_gacha_version_prizes WHERE gacha_version_id = version_id;
                SELECT COALESCE(SUM(inventory.total_quantity), 0) INTO operational_total
                FROM prize_inventories inventory JOIN catalog_gacha_version_prizes relation ON relation.id = inventory.gacha_version_prize_id
                WHERE relation.gacha_version_id = version_id;
                IF snapshot_total > capacity OR operational_total > capacity THEN
                    RAISE EXCEPTION 'Aggregate Gacha Prize inventory cannot exceed total count';
                END IF;
                RETURN NULL;
            END;
            $$;
        SQL);
    }

    private function salesStateGuard(): void
    {
        DB::unprepared(<<<'SQL'
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
                    INNER JOIN gacha_draw_states AS state ON state.gacha_id = g.id
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
                IF NEW.sales_paused IS DISTINCT FROM OLD.sales_paused
                   OR NEW.sales_paused_at IS DISTINCT FROM OLD.sales_paused_at
                   OR NEW.sales_paused_by_admin_public_id IS DISTINCT FROM OLD.sales_paused_by_admin_public_id
                   OR NEW.sales_pause_reason_code IS DISTINCT FROM OLD.sales_pause_reason_code
                   OR NEW.sales_resumed_at IS DISTINCT FROM OLD.sales_resumed_at
                   OR NEW.sales_last_mutation_request_id IS DISTINCT FROM OLD.sales_last_mutation_request_id THEN
                    IF NEW.sales_paused IS NOT DISTINCT FROM OLD.sales_paused
                       OR NEW.revision IS DISTINCT FROM OLD.revision + 1
                       OR NEW.public_id IS DISTINCT FROM OLD.public_id
                       OR NEW.code IS DISTINCT FROM OLD.code
                       OR NEW.slug IS DISTINCT FROM OLD.slug
                       OR NEW.category_id IS DISTINCT FROM OLD.category_id
                       OR NEW.state IS DISTINCT FROM OLD.state
                       OR NEW.sold_count IS DISTINCT FROM OLD.sold_count
                       OR NEW.published_version_id IS DISTINCT FROM OLD.published_version_id
                       OR NEW.active_draw_state_id IS DISTINCT FROM OLD.active_draw_state_id
                       OR NEW.archived_at IS DISTINCT FROM OLD.archived_at THEN
                        RAISE EXCEPTION 'Gacha Sales state requires one Revision transition';
                    END IF;
                    IF NEW.published_version_id IS NULL
                       OR NEW.active_draw_state_id IS NULL
                       OR NEW.archived_at IS NOT NULL
                       OR NEW.state::text <> 'active'::text THEN
                        RAISE EXCEPTION 'Gacha Sales state requires an active Published Gacha';
                    END IF;
                    SELECT * INTO version_row FROM catalog_gacha_versions
                    WHERE id = NEW.published_version_id;
                    SELECT * INTO state_row FROM gacha_draw_states
                    WHERE id = NEW.active_draw_state_id;
                    SELECT * INTO probability_row FROM catalog_probability_versions
                    WHERE id = version_row.published_probability_version_id;
                    IF version_row.id IS NULL
                       OR version_row.gacha_id IS DISTINCT FROM NEW.id
                       OR version_row.status::text <> 'published'::text
                       OR version_row.archived_at IS NOT NULL
                       OR version_row.published_at IS NULL
                       OR state_row.id IS NULL
                       OR state_row.gacha_id IS DISTINCT FROM NEW.id
                       OR state_row.gacha_version_id IS DISTINCT FROM version_row.id
                       OR state_row.probability_version_id IS DISTINCT FROM probability_row.id
                       OR NOT (state_row.status = 'selling' OR (state_row.status = 'sold_out' AND EXISTS (
                            SELECT 1 FROM prize_inventories inventory
                            WHERE inventory.gacha_draw_state_id = state_row.id AND inventory.available_quantity > 0
                        )))
                       OR probability_row.status::text <> 'published'::text
                       OR probability_row.archived_at IS NOT NULL
                       OR probability_row.snapshot_sha256 !~ '^[0-9a-f]{64}$' THEN
                        RAISE EXCEPTION 'Gacha Sales state requires matching active Draw references';
                    END IF;
                    IF NEW.sales_paused = FALSE THEN
                        IF (NEW.gacha_type = 'standard' AND NOT EXISTS (
                            SELECT 1 FROM prize_inventories inventory
                            WHERE inventory.gacha_draw_state_id = state_row.id AND inventory.available_quantity > 0
                        ))
                           OR COALESCE(NEW.current_publish_start_at, version_row.publish_start_at) > CURRENT_TIMESTAMP
                           OR (NEW.current_publish_end_at IS NOT NULL AND NEW.current_publish_end_at <= CURRENT_TIMESTAMP)
                           OR EXISTS (
                               SELECT 1 FROM catalog_gacha_publish_schedules
                               WHERE gacha_id = NEW.id AND status::text = 'processing'::text
                           ) THEN
                            RAISE EXCEPTION 'Gacha Sales Resume preflight failed';
                        END IF;
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
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
                       OR NEW.requested_by_admin_id IS DISTINCT FROM OLD.requested_by_admin_id
                       OR NEW.request_id IS DISTINCT FROM OLD.request_id
                       OR NEW.created_at IS DISTINCT FROM OLD.created_at
                       OR NEW.revision IS DISTINCT FROM OLD.revision + 1 THEN
                        RAISE EXCEPTION 'Gacha Publish Schedule identity and revision are immutable';
                    END IF;
                    IF OLD.status = 'scheduled' AND NEW.status = 'scheduled' THEN
                        IF NEW.scheduled_for <= clock_timestamp()
                           OR NEW.next_attempt_at IS DISTINCT FROM NEW.scheduled_for THEN
                            RAISE EXCEPTION 'Gacha Publish Schedule revision must remain future';
                        END IF;
                    ELSIF NOT (
                        (OLD.status = 'scheduled' AND NEW.status IN ('processing', 'cancelled'))
                        OR (OLD.status = 'processing' AND NEW.status IN ('scheduled', 'completed', 'failed'))
                    ) THEN
                        RAISE EXCEPTION 'Invalid Gacha Publish Schedule transition';
                    END IF;
                ELSIF NEW.scheduled_for <= CURRENT_TIMESTAMP THEN
                    RAISE EXCEPTION 'Gacha Publish Schedule must be in the future';
                END IF;
                IF NEW.status IN ('scheduled', 'processing') THEN
                    SELECT * INTO version_row FROM catalog_gacha_versions
                    WHERE id = NEW.gacha_version_id;
                    SELECT * INTO probability_row FROM catalog_probability_versions
                    WHERE id = NEW.probability_version_id;
                    IF version_row.id IS NULL
                       OR version_row.gacha_id IS DISTINCT FROM NEW.gacha_id
                       OR version_row.status::text <> 'draft'::text
                       OR version_row.archived_at IS NOT NULL
                       OR version_row.revision IS DISTINCT FROM NEW.expected_version_revision
                       OR probability_row.id IS NULL
                       OR probability_row.gacha_version_id IS DISTINCT FROM version_row.id
                                   OR probability_row.status::text NOT IN ('draft'::text, 'published'::text)
                       OR (
                           probability_row.status::text = 'draft'::text AND NOT EXISTS (
                               SELECT 1 FROM catalog_probability_stages stage
                               JOIN catalog_gachas gacha ON gacha.id = version_row.gacha_id
                               WHERE stage.probability_version_id = probability_row.id
                                 AND ((gacha.gacha_type = 'standard' AND stage.code = '__canonical_inventory_v1')
                                   OR (gacha.gacha_type IN ('login_daily', 'signup_once') AND stage.code = '__login_fixed_10_v1'))
                           )
                       )
                       OR probability_row.archived_at IS NOT NULL
                       OR probability_row.snapshot_sha256 !~ '^[0-9a-f]{64}$' THEN
                        RAISE EXCEPTION 'Gacha Publish Schedule requires its Draft and canonical Probability metadata';
                    END IF;
                    IF NOT EXISTS (
                        SELECT 1 FROM catalog_gachas
                        WHERE id = NEW.gacha_id
                          AND revision = NEW.expected_gacha_revision
                          AND archived_at IS NULL
                    ) THEN
                        RAISE EXCEPTION 'Gacha Publish Schedule revision is stale';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
    }

    private function scheduledPresentationGuard(): void
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
                    SELECT * INTO active_schedule FROM catalog_gacha_publish_schedules
                    WHERE gacha_id = OLD.id AND status IN ('scheduled', 'processing') LIMIT 1;
                    IF active_schedule.id IS NULL THEN RETURN NEW; END IF;
                    IF active_schedule.status = 'scheduled' THEN
                        RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                    END IF;
                    IF active_schedule.status = 'processing'
                       AND OLD.management_status = 'scheduled'
                       AND NEW.management_status = 'published'
                       AND OLD.first_published_at IS NULL
                       AND NEW.first_published_at IS NOT NULL
                       AND NEW.published_version_id IS DISTINCT FROM OLD.published_version_id
                       AND NEW.active_draw_state_id IS DISTINCT FROM OLD.active_draw_state_id
                       AND NEW.revision IS NOT DISTINCT FROM OLD.revision + 1
                       AND NEW.category_id IS NOT DISTINCT FROM OLD.category_id
                       AND NEW.state = 'active'
                       AND NEW.archived_at IS NOT DISTINCT FROM OLD.archived_at THEN
                        RETURN NEW;
                    END IF;
                    RAISE EXCEPTION 'Scheduled Gacha Master is immutable while processing';
                END IF;
                IF TG_TABLE_NAME = 'catalog_gacha_versions' THEN
                    SELECT * INTO active_schedule FROM catalog_gacha_publish_schedules
                    WHERE gacha_version_id = OLD.id AND status IN ('scheduled', 'processing') LIMIT 1;
                    IF active_schedule.id IS NULL THEN RETURN NEW; END IF;
                    IF active_schedule.status = 'scheduled' THEN
                        RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                    END IF;
                    IF active_schedule.status = 'processing'
                       AND OLD.status = 'draft' AND NEW.status = 'published'
                       AND NEW.published_at IS NOT NULL
                       AND NEW.revision IS NOT DISTINCT FROM OLD.revision + 1
                       AND NEW.gacha_id IS NOT DISTINCT FROM OLD.gacha_id
                       AND NEW.published_probability_version_id IS NOT DISTINCT FROM active_schedule.probability_version_id
                       AND NEW.archived_at IS NOT DISTINCT FROM OLD.archived_at THEN
                        RETURN NEW;
                    END IF;
                    RAISE EXCEPTION 'Scheduled Gacha Version is immutable while processing';
                END IF;
                IF TG_TABLE_NAME = 'catalog_gacha_tags' THEN
                    parent_gacha_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.gacha_id ELSE NEW.gacha_id END;
                ELSE
                    SELECT gacha_id INTO parent_gacha_id FROM catalog_gacha_versions
                    WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.gacha_version_id ELSE NEW.gacha_version_id END;
                END IF;
                SELECT * INTO active_schedule FROM catalog_gacha_publish_schedules
                WHERE gacha_id = parent_gacha_id AND status IN ('scheduled', 'processing') LIMIT 1;
                IF active_schedule.id IS NULL THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;
                IF active_schedule.status = 'scheduled' THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;
                IF TG_TABLE_NAME = 'catalog_gacha_version_prizes' AND TG_OP = 'UPDATE' THEN
                    IF active_schedule.status = 'processing'
                       AND NEW.gacha_version_id = active_schedule.gacha_version_id
                       AND OLD.published_rank_revision_id IS NULL AND OLD.published_video_revision_id IS NULL
                       AND NEW.published_rank_revision_id IS NOT NULL AND NEW.published_video_revision_id IS NOT NULL
                       AND (to_jsonb(NEW) - 'published_rank_revision_id' - 'published_video_revision_id')
                           IS NOT DISTINCT FROM (to_jsonb(OLD) - 'published_rank_revision_id' - 'published_video_revision_id')
                       AND EXISTS (SELECT 1 FROM catalog_gachas WHERE id = parent_gacha_id AND gacha_type IN ('login_daily', 'signup_once')) THEN
                        RETURN NEW;
                    END IF;
                END IF;
                RAISE EXCEPTION 'Scheduled Gacha relations are immutable while processing';
            END;
            $$
        SQL);
    }

    private function probabilityGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_login_validate_composition(target_version_id bigint) RETURNS void LANGUAGE plpgsql AS $$
            DECLARE kind text;
            DECLARE minimum_points bigint;
            DECLARE selected_probability bigint;
            DECLARE selected_stage bigint;
            DECLARE prize_count bigint;
            BEGIN
                SELECT gacha.gacha_type, version.minimum_exchange_points INTO kind, minimum_points
                FROM catalog_gacha_versions version JOIN catalog_gachas gacha ON gacha.id = version.gacha_id
                WHERE version.id = target_version_id FOR UPDATE OF version;
                IF kind = 'standard' OR kind IS NULL THEN RETURN; END IF;
                IF kind NOT IN ('login_daily', 'signup_once') THEN RAISE EXCEPTION 'Unknown Gacha type'; END IF;
                SELECT COUNT(*) INTO prize_count FROM catalog_gacha_version_prizes WHERE gacha_version_id = target_version_id;
                IF prize_count = 0 OR EXISTS (
                    SELECT 1 FROM catalog_gacha_version_prizes relation
                    LEFT JOIN prize_inventories inventory ON inventory.gacha_version_prize_id = relation.id
                    WHERE relation.gacha_version_id = target_version_id
                      AND (relation.shipping_only OR NOT relation.is_visible OR inventory.id IS NULL
                        OR (kind = 'login_daily' AND relation.exchange_points < minimum_points))
                ) THEN RAISE EXCEPTION 'Login Prize composition is incomplete or invalid'; END IF;
                IF (SELECT COUNT(*) FROM catalog_probability_versions WHERE gacha_version_id = target_version_id AND archived_at IS NULL) <> 1 THEN
                    RAISE EXCEPTION 'Login Gacha requires one Canonical Probability';
                END IF;
                SELECT id INTO selected_probability FROM catalog_probability_versions
                WHERE gacha_version_id = target_version_id AND archived_at IS NULL;
                IF (SELECT COUNT(*) FROM catalog_probability_stages WHERE probability_version_id = selected_probability) <> 1 THEN
                    RAISE EXCEPTION 'Login Probability requires one fixed Stage';
                END IF;
                SELECT id INTO selected_stage FROM catalog_probability_stages
                WHERE probability_version_id = selected_probability AND min_draw_number = 1 AND max_draw_number IS NULL
                  AND code = '__login_fixed_10_v1' AND condition_type = 'sold_count';
                IF selected_stage IS NULL OR EXISTS (SELECT 1 FROM catalog_minimum_guarantees WHERE probability_stage_id = selected_stage) THEN
                    RAISE EXCEPTION 'Login Probability cannot have stages or minimum guarantees';
                END IF;
                IF (SELECT COUNT(*) FROM catalog_probability_entries WHERE probability_stage_id = selected_stage) <> prize_count
                   OR (SELECT COALESCE(SUM(rate_units), 0) FROM catalog_probability_entries WHERE probability_stage_id = selected_stage) <> 1000000000000
                   OR EXISTS (
                    SELECT 1 FROM catalog_gacha_version_prizes relation
                    WHERE relation.gacha_version_id = target_version_id AND (
                        SELECT COUNT(*) FROM catalog_probability_entries entry
                        WHERE entry.probability_stage_id = selected_stage AND entry.gacha_version_prize_id = relation.id
                          AND entry.result_type = 'prize' AND entry.probability_ppm IS NULL AND entry.rate_units > 0
                    ) <> 1
                ) THEN RAISE EXCEPTION 'Login fixed rates must exactly cover every Prize and total 1000000000000 units'; END IF;
            END;
            $$;
            CREATE OR REPLACE FUNCTION v2_login_composition_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE row_data jsonb;
            DECLARE version_id bigint;
            BEGIN
                row_data := CASE WHEN TG_OP = 'DELETE' THEN to_jsonb(OLD) ELSE to_jsonb(NEW) END;
                IF TG_TABLE_NAME = 'catalog_gacha_versions' THEN version_id := (row_data->>'id')::bigint;
                ELSIF TG_TABLE_NAME IN ('catalog_gacha_version_prizes', 'catalog_probability_versions') THEN version_id := (row_data->>'gacha_version_id')::bigint;
                ELSIF TG_TABLE_NAME = 'catalog_probability_stages' THEN
                    SELECT gacha_version_id INTO version_id FROM catalog_probability_versions WHERE id = (row_data->>'probability_version_id')::bigint;
                ELSIF TG_TABLE_NAME = 'prize_inventories' THEN
                    SELECT gacha_version_id INTO version_id FROM catalog_gacha_version_prizes WHERE id = (row_data->>'gacha_version_prize_id')::bigint;
                ELSE
                    SELECT probability.gacha_version_id INTO version_id FROM catalog_probability_stages stage
                    JOIN catalog_probability_versions probability ON probability.id = stage.probability_version_id
                    WHERE stage.id = (row_data->>'probability_stage_id')::bigint;
                END IF;
                PERFORM v2_login_validate_composition(version_id);
                RETURN NULL;
            END;
            $$;
            CREATE OR REPLACE FUNCTION v2_login_composition_applies(table_name text, row_data jsonb) RETURNS boolean LANGUAGE sql AS $$
                SELECT EXISTS (
                    SELECT 1 FROM catalog_gachas
                    WHERE id = v2_login_owner(table_name, row_data) AND gacha_type IN ('login_daily', 'signup_once')
                );
            $$;
            CREATE OR REPLACE FUNCTION v2_probability_entry_precision_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE kind text;
            BEGIN
                SELECT gacha.gacha_type INTO STRICT kind FROM catalog_probability_stages stage
                JOIN catalog_probability_versions probability ON probability.id = stage.probability_version_id
                JOIN catalog_gacha_versions version ON version.id = probability.gacha_version_id
                JOIN catalog_gachas gacha ON gacha.id = version.gacha_id WHERE stage.id = NEW.probability_stage_id;
                IF (kind = 'standard' AND (NEW.rate_units IS NOT NULL OR NEW.probability_ppm IS NULL))
                   OR (kind <> 'standard' AND (NEW.rate_units IS NULL OR NEW.probability_ppm IS NOT NULL OR NEW.result_type <> 'prize')) THEN
                    RAISE EXCEPTION 'Probability precision must match Canonical Gacha type';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER catalog_probability_entries_precision_guard BEFORE INSERT OR UPDATE ON catalog_probability_entries
                FOR EACH ROW EXECUTE FUNCTION v2_probability_entry_precision_guard();
            CREATE OR REPLACE FUNCTION v2_catalog_validate_probability_publish() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE kind text;
            DECLARE stage_count bigint;
            DECLARE invalid_stage_count bigint;
            BEGIN
                IF OLD.status = 'published' THEN RAISE EXCEPTION 'Published Probability Version is immutable'; END IF;
                IF NEW.status = 'published' THEN
                    SELECT gacha.gacha_type INTO STRICT kind FROM catalog_gacha_versions version
                    JOIN catalog_gachas gacha ON gacha.id = version.gacha_id WHERE version.id = NEW.gacha_version_id;
                    IF kind IN ('login_daily', 'signup_once') THEN
                        PERFORM v2_login_validate_composition(NEW.gacha_version_id);
                    ELSE
                        SELECT COUNT(*) INTO stage_count FROM catalog_probability_stages WHERE probability_version_id = NEW.id;
                        IF stage_count = 0 THEN RAISE EXCEPTION 'Published Probability Version requires a Stage'; END IF;
                        SELECT COUNT(*) INTO invalid_stage_count FROM catalog_probability_stages stage
                        LEFT JOIN LATERAL (
                            SELECT COALESCE(SUM(entry.probability_ppm), 0) AS entry_total
                            FROM catalog_probability_entries entry WHERE entry.probability_stage_id = stage.id
                        ) entries ON true
                        LEFT JOIN catalog_minimum_guarantees guarantee ON guarantee.probability_stage_id = stage.id
                        WHERE stage.probability_version_id = NEW.id
                          AND (guarantee.id IS NULL OR entries.entry_total + guarantee.probability_ppm <> 1000000);
                        IF invalid_stage_count <> 0 THEN RAISE EXCEPTION 'Each Probability Stage must total 1000000 ppm'; END IF;
                    END IF;
                    NEW.published_at := COALESCE(NEW.published_at, CURRENT_TIMESTAMP);
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);
        foreach (['catalog_gacha_versions', 'catalog_gacha_version_prizes', 'catalog_probability_versions',
            'catalog_probability_stages', 'catalog_probability_entries', 'catalog_minimum_guarantees', 'prize_inventories'] as $tableName) {
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $operation) {
                $rowName = $operation === 'DELETE' ? 'OLD' : 'NEW';
                $suffix = strtolower($operation);
                DB::statement("CREATE CONSTRAINT TRIGGER {$tableName}_login_composition_{$suffix}_check ".
                    "AFTER {$operation} ON {$tableName} DEFERRABLE INITIALLY DEFERRED ".
                    "FOR EACH ROW WHEN (v2_login_composition_applies('{$tableName}', to_jsonb({$rowName}))) ".
                    'EXECUTE FUNCTION v2_login_composition_guard()');
            }
        }
    }

    private function immutabilityGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_login_guard_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE parent_id bigint;
            DECLARE parent catalog_gachas%ROWTYPE;
            DECLARE row_data jsonb;
            BEGIN
                row_data := CASE WHEN TG_OP = 'DELETE' THEN to_jsonb(OLD) ELSE to_jsonb(NEW) END;
                IF TG_TABLE_NAME = 'catalog_gachas' THEN parent_id := OLD.id;
                ELSIF TG_TABLE_NAME IN ('catalog_prizes', 'catalog_gacha_ranks', 'catalog_gacha_tags') THEN parent_id := (row_data->>'gacha_id')::bigint;
                ELSIF TG_TABLE_NAME = 'catalog_gacha_rank_video_revisions' THEN
                    SELECT gacha_id INTO parent_id FROM catalog_gacha_ranks WHERE id = (row_data->>'gacha_rank_id')::bigint;
                ELSIF TG_TABLE_NAME = 'prize_inventories' THEN
                    SELECT version.gacha_id INTO parent_id FROM catalog_gacha_version_prizes relation
                    JOIN catalog_gacha_versions version ON version.id = relation.gacha_version_id
                    WHERE relation.id = (row_data->>'gacha_version_prize_id')::bigint;
                ELSE
                    SELECT gacha_id INTO parent_id FROM catalog_gacha_versions WHERE id = (row_data->>'gacha_version_id')::bigint;
                END IF;
                SELECT * INTO parent FROM catalog_gachas WHERE id = parent_id FOR UPDATE;
                IF parent.gacha_type = 'standard' OR parent.id IS NULL THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;
                IF TG_TABLE_NAME IN ('catalog_gacha_tags', 'catalog_gacha_version_tags') THEN
                    RAISE EXCEPTION 'Login Gacha has no Tags';
                END IF;
                IF TG_TABLE_NAME = 'catalog_prizes' AND TG_OP <> 'DELETE' THEN
                    IF NEW.shipping_only OR EXISTS (
                        SELECT 1 FROM catalog_gacha_versions version WHERE version.gacha_id = parent.id
                        AND version.archived_at IS NULL AND parent.gacha_type = 'login_daily'
                        AND NEW.exchange_points < version.minimum_exchange_points
                    ) THEN
                        RAISE EXCEPTION 'Login Prize cannot be shipping-only or below its minimum exchange value';
                    END IF;
                END IF;
                IF TG_TABLE_NAME = 'catalog_gacha_ranks' AND TG_OP <> 'DELETE' THEN
                    IF NEW.preferred_rank_revision_id IS NOT NULL
                       AND NOT EXISTS (SELECT 1 FROM catalog_rank_master_revisions WHERE id = NEW.preferred_rank_revision_id AND rank_master_id = NEW.rank_master_id) THEN
                        RAISE EXCEPTION 'Rank revision must belong to its Master';
                    END IF;
                END IF;
                IF parent.first_published_at IS NOT NULL THEN
                    IF TG_TABLE_NAME = 'catalog_gachas' THEN
                        IF TG_OP = 'DELETE' OR
                           (NEW.code, NEW.slug, NEW.category_id, NEW.current_title, NEW.current_description, NEW.current_notices,
                            NEW.current_presentation_asset_id, NEW.current_publish_start_at, NEW.current_publish_end_at)
                           IS DISTINCT FROM
                           (OLD.code, OLD.slug, OLD.category_id, OLD.current_title, OLD.current_description, OLD.current_notices,
                            OLD.current_presentation_asset_id, OLD.current_publish_start_at, OLD.current_publish_end_at) THEN
                            RAISE EXCEPTION 'Published login Gacha content is immutable';
                        END IF;
                    ELSIF TG_TABLE_NAME = 'prize_inventories' THEN
                        IF parent.management_status <> 'published' OR TG_OP <> 'UPDATE' THEN
                            RAISE EXCEPTION 'Only Published login Gacha inventory may be adjusted';
                        END IF;
                    ELSE
                        RAISE EXCEPTION 'Published login Gacha configuration is immutable';
                    END IF;
                END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$;
        SQL);
        foreach (['catalog_gachas', 'catalog_prizes', 'catalog_gacha_ranks', 'catalog_gacha_rank_video_revisions',
            'catalog_gacha_version_prizes', 'catalog_gacha_tags', 'catalog_gacha_version_tags', 'prize_inventories'] as $tableName) {
            $operations = $tableName === 'catalog_gachas' ? 'UPDATE OR DELETE' : 'INSERT OR UPDATE OR DELETE';
            DB::statement("CREATE TRIGGER {$tableName}_login_mutation_guard BEFORE {$operations} ON {$tableName} ".
                'FOR EACH ROW EXECUTE FUNCTION v2_login_guard_mutation()');
        }
    }
};
