<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_rank_masters', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('current_revision_id')->nullable();
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('revision')->default(1);
            $table->timestampsTz();
            $table->index(['status', 'public_id']);
        });

        Schema::create('catalog_rank_master_revisions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('rank_master_id')
                ->constrained('catalog_rank_masters')->restrictOnDelete();
            $table->unsignedInteger('revision_number');
            $table->string('rank_name', 128);
            $table->foreignId('lineup_image_asset_id')
                ->constrained('catalog_presentation_assets')->restrictOnDelete();
            $table->foreignId('result_image_asset_id')
                ->constrained('catalog_presentation_assets')->restrictOnDelete();
            $table->boolean('show_total_stock')->default(false);
            $table->unsignedInteger('display_order')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['rank_master_id', 'revision_number']);
            $table->index(['display_order', 'rank_master_id']);
        });

        Schema::table('catalog_rank_masters', function (Blueprint $table): void {
            $table->foreign('current_revision_id')
                ->references('id')->on('catalog_rank_master_revisions')
                ->restrictOnDelete();
        });

        Schema::create('catalog_gacha_ranks', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('gacha_id')
                ->constrained('catalog_gachas')->restrictOnDelete();
            $table->foreignId('rank_master_id')
                ->constrained('catalog_rank_masters')->restrictOnDelete();
            $table->unsignedBigInteger('current_video_revision_id')->nullable();
            $table->timestampTz('first_published_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestampsTz();
            $table->unique(['gacha_id', 'rank_master_id']);
            $table->unique(['id', 'gacha_id']);
        });

        Schema::create('catalog_gacha_rank_video_revisions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('gacha_rank_id')
                ->constrained('catalog_gacha_ranks')->restrictOnDelete();
            $table->unsignedInteger('revision_number');
            $table->foreignId('video_asset_id')
                ->constrained('catalog_presentation_assets')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['gacha_rank_id', 'revision_number']);
        });

        Schema::table('catalog_gacha_ranks', function (Blueprint $table): void {
            $table->foreign('current_video_revision_id')
                ->references('id')->on('catalog_gacha_rank_video_revisions')
                ->restrictOnDelete();
        });

        Schema::create('catalog_rank_effect_materials', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('presentation_asset_id')->unique()
                ->constrained('catalog_presentation_assets')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::statement(<<<'SQL'
            INSERT INTO catalog_rank_effect_materials (presentation_asset_id, created_at)
            SELECT candidate.presentation_asset_id, CURRENT_TIMESTAMP
            FROM (
                SELECT asset.id AS presentation_asset_id
                FROM catalog_presentation_assets AS asset
                WHERE asset.storage_identifier LIKE 'admin-assets/rank-effects/%'
                UNION
                SELECT relation.presentation_asset_id
                FROM catalog_rank_assets AS relation
                WHERE relation.usage_type IN ('image', 'video')
            ) AS candidate
            ON CONFLICT (presentation_asset_id) DO NOTHING
        SQL);

        Schema::table('catalog_prizes', function (Blueprint $table): void {
            $table->foreignId('gacha_rank_id')->nullable()->after('rank_id')
                ->constrained('catalog_gacha_ranks')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE catalog_prizes ALTER COLUMN rank_id DROP NOT NULL');
        DB::statement(
            'ALTER TABLE catalog_prizes ADD CONSTRAINT catalog_prizes_rank_authority_check '.
            'CHECK ((rank_id IS NOT NULL) <> (gacha_rank_id IS NOT NULL))'
        );
        DB::statement(
            'ALTER TABLE catalog_prizes ADD CONSTRAINT catalog_prizes_gacha_rank_scope_foreign '.
            'FOREIGN KEY (gacha_rank_id, gacha_id) REFERENCES catalog_gacha_ranks (id, gacha_id) '.
            'ON DELETE RESTRICT'
        );

        Schema::table('catalog_gacha_version_prizes', function (Blueprint $table): void {
            $table->foreignId('gacha_rank_id')->nullable()->after('rank_id')
                ->constrained('catalog_gacha_ranks')->restrictOnDelete();
        });
        foreach (['rank_id', 'rank_code', 'rank_display_name', 'rank_sort_order'] as $column) {
            DB::statement(
                "ALTER TABLE catalog_gacha_version_prizes ALTER COLUMN {$column} DROP NOT NULL"
            );
        }
        DB::statement(
            'ALTER TABLE catalog_gacha_version_prizes ADD CONSTRAINT '.
            'catalog_gacha_version_prizes_rank_authority_check '.
            'CHECK ((rank_id IS NOT NULL) <> (gacha_rank_id IS NOT NULL))'
        );

        Schema::table('draw_results', function (Blueprint $table): void {
            $table->foreignId('rank_master_revision_id')->nullable()->after('rank_id')
                ->constrained('catalog_rank_master_revisions')->restrictOnDelete();
            $table->foreignId('gacha_rank_video_revision_id')->nullable()
                ->after('rank_master_revision_id')
                ->constrained('catalog_gacha_rank_video_revisions')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE draw_results DROP CONSTRAINT draw_result_values_check');
        DB::statement(<<<'SQL'
            ALTER TABLE draw_results ADD CONSTRAINT draw_result_values_check CHECK (
                request_sequence > 0
                AND draw_sequence_number > 0
                AND consumed_points > 0
                AND point_back_amount >= 0
                AND random_value <= 999999
                AND display_snapshot_sha256 ~ '^[0-9a-f]{64}$'
                AND jsonb_typeof(display_snapshot) = 'object'
                AND (
                    (
                        result_type = 'prize'
                        AND gacha_version_prize_id IS NOT NULL
                        AND point_back_amount = 0
                        AND (
                            (
                                rank_id IS NOT NULL
                                AND rank_master_revision_id IS NULL
                                AND gacha_rank_video_revision_id IS NULL
                            )
                            OR
                            (
                                rank_id IS NULL
                                AND rank_master_revision_id IS NOT NULL
                                AND gacha_rank_video_revision_id IS NOT NULL
                            )
                        )
                    )
                    OR
                    (
                        result_type = 'point_back'
                        AND gacha_version_prize_id IS NULL
                        AND rank_id IS NULL
                        AND rank_master_revision_id IS NULL
                        AND gacha_rank_video_revision_id IS NULL
                        AND point_back_amount >= 0
                    )
                )
            )
        SQL);

        $this->constraintsAndTriggers();
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM catalog_rank_masters)
                   OR EXISTS (SELECT 1 FROM catalog_gacha_ranks)
                   OR EXISTS (SELECT 1 FROM catalog_prizes WHERE gacha_rank_id IS NOT NULL)
                   OR EXISTS (
                       SELECT 1 FROM draw_results
                       WHERE rank_master_revision_id IS NOT NULL
                          OR gacha_rank_video_revision_id IS NOT NULL
                   ) THEN
                    RAISE EXCEPTION 'Canonical Rank data prevents safe migration rollback';
                END IF;
                IF EXISTS (
                    SELECT 1
                    FROM catalog_rank_effect_materials material
                    WHERE NOT EXISTS (
                        SELECT 1
                        FROM catalog_rank_assets relation
                        WHERE relation.presentation_asset_id = material.presentation_asset_id
                          AND relation.usage_type IN ('image', 'video')
                    )
                      AND NOT EXISTS (
                        SELECT 1
                        FROM catalog_presentation_assets asset
                        WHERE asset.id = material.presentation_asset_id
                          AND asset.storage_identifier LIKE 'admin-assets/rank-effects/%'
                    )
                ) THEN
                    RAISE EXCEPTION 'Canonical Rank Effect Material data prevents safe rollback';
                END IF;
            END;
            $$
        SQL);

        $this->dropConstraintsAndTriggers();

        DB::statement('ALTER TABLE draw_results DROP CONSTRAINT draw_result_values_check');
        DB::statement(<<<'SQL'
            ALTER TABLE draw_results ADD CONSTRAINT draw_result_values_check CHECK (
                request_sequence > 0
                AND draw_sequence_number > 0
                AND consumed_points > 0
                AND point_back_amount >= 0
                AND random_value <= 999999
                AND display_snapshot_sha256 ~ '^[0-9a-f]{64}$'
                AND jsonb_typeof(display_snapshot) = 'object'
                AND (
                    (
                        result_type = 'prize'
                        AND gacha_version_prize_id IS NOT NULL
                        AND rank_id IS NOT NULL
                        AND point_back_amount = 0
                    )
                    OR
                    (
                        result_type = 'point_back'
                        AND gacha_version_prize_id IS NULL
                        AND rank_id IS NULL
                        AND point_back_amount >= 0
                    )
                )
            )
        SQL);
        Schema::table('draw_results', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('gacha_rank_video_revision_id');
            $table->dropConstrainedForeignId('rank_master_revision_id');
        });

        DB::statement(
            'ALTER TABLE catalog_gacha_version_prizes DROP CONSTRAINT '.
            'catalog_gacha_version_prizes_rank_authority_check'
        );
        Schema::table('catalog_gacha_version_prizes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('gacha_rank_id');
        });
        foreach (['rank_id', 'rank_code', 'rank_display_name', 'rank_sort_order'] as $column) {
            DB::statement(
                "ALTER TABLE catalog_gacha_version_prizes ALTER COLUMN {$column} SET NOT NULL"
            );
        }

        DB::statement(
            'ALTER TABLE catalog_prizes DROP CONSTRAINT catalog_prizes_gacha_rank_scope_foreign'
        );
        DB::statement(
            'ALTER TABLE catalog_prizes DROP CONSTRAINT catalog_prizes_rank_authority_check'
        );
        Schema::table('catalog_prizes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('gacha_rank_id');
        });
        DB::statement('ALTER TABLE catalog_prizes ALTER COLUMN rank_id SET NOT NULL');

        Schema::dropIfExists('catalog_rank_effect_materials');
        Schema::table('catalog_gacha_ranks', function (Blueprint $table): void {
            $table->dropForeign(['current_video_revision_id']);
        });
        Schema::dropIfExists('catalog_gacha_rank_video_revisions');
        Schema::dropIfExists('catalog_gacha_ranks');
        Schema::table('catalog_rank_masters', function (Blueprint $table): void {
            $table->dropForeign(['current_revision_id']);
        });
        Schema::dropIfExists('catalog_rank_master_revisions');
        Schema::dropIfExists('catalog_rank_masters');
    }

    private function constraintsAndTriggers(): void
    {
        DB::statement(
            "ALTER TABLE catalog_rank_masters ADD CONSTRAINT catalog_rank_masters_status_check ".
            "CHECK (status IN ('active', 'inactive'))"
        );
        DB::statement(
            'ALTER TABLE catalog_rank_master_revisions ADD CONSTRAINT '.
            'catalog_rank_master_revision_values_check CHECK (revision_number > 0)'
        );
        DB::statement(
            'ALTER TABLE catalog_gacha_rank_video_revisions ADD CONSTRAINT '.
            'catalog_gacha_rank_video_revision_values_check CHECK (revision_number > 0)'
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_guard_rank_master()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Catalog Rank Masters cannot be deleted';
                END IF;
                IF OLD.status = 'active' AND NEW.status = 'inactive' AND (
                    EXISTS (
                        SELECT 1
                        FROM catalog_prizes AS prize
                        JOIN catalog_gacha_ranks AS gacha_rank
                          ON gacha_rank.id = prize.gacha_rank_id
                        WHERE gacha_rank.rank_master_id = OLD.id
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM catalog_gacha_ranks AS gacha_rank
                        WHERE gacha_rank.rank_master_id = OLD.id
                          AND gacha_rank.first_published_at IS NOT NULL
                    )
                ) THEN
                    RAISE EXCEPTION 'Rank Masters with usage history cannot be made inactive';
                END IF;
                IF OLD.current_revision_id IS NULL
                   AND NEW.current_revision_id IS NOT NULL
                   AND NEW.revision = OLD.revision THEN
                    RETURN NEW;
                END IF;
                IF NEW.revision <> OLD.revision + 1 THEN
                    RAISE EXCEPTION 'Catalog Rank Master revision must increase by one';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER catalog_rank_masters_guard_mutation '.
            'BEFORE UPDATE OR DELETE ON catalog_rank_masters FOR EACH ROW '.
            'EXECUTE FUNCTION v2_catalog_guard_rank_master()'
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_validate_rank_master_current_revision()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE current_owner_id bigint;
            DECLARE current_pointer_id bigint;
            BEGIN
                SELECT current_revision_id
                  INTO current_pointer_id
                FROM catalog_rank_masters
                WHERE id = NEW.id;
                SELECT rank_master_id
                  INTO current_owner_id
                FROM catalog_rank_master_revisions
                WHERE id = current_pointer_id;
                IF current_pointer_id IS NULL OR current_owner_id IS DISTINCT FROM NEW.id THEN
                    RAISE EXCEPTION 'Catalog Rank Master current Revision is invalid';
                END IF;
                RETURN NULL;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE CONSTRAINT TRIGGER catalog_rank_masters_current_revision_guard '.
            'AFTER INSERT OR UPDATE OF current_revision_id ON catalog_rank_masters '.
            'DEFERRABLE INITIALLY DEFERRED FOR EACH ROW '.
            'EXECUTE FUNCTION v2_catalog_validate_rank_master_current_revision()'
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_guard_rank_master_revision()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE lineup_type text;
            DECLARE result_type text;
            BEGIN
                IF TG_OP IN ('UPDATE', 'DELETE') THEN
                    RAISE EXCEPTION 'Catalog Rank Master Revisions are immutable';
                END IF;
                SELECT media_type INTO lineup_type
                FROM catalog_presentation_assets
                WHERE id = NEW.lineup_image_asset_id;
                SELECT media_type INTO result_type
                FROM catalog_presentation_assets
                WHERE id = NEW.result_image_asset_id;
                IF lineup_type IS DISTINCT FROM 'image' OR result_type IS DISTINCT FROM 'image' THEN
                    RAISE EXCEPTION 'Catalog Rank Master Revision requires image Assets';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER catalog_rank_master_revisions_guard_mutation '.
            'BEFORE INSERT OR UPDATE OR DELETE ON catalog_rank_master_revisions '.
            'FOR EACH ROW EXECUTE FUNCTION v2_catalog_guard_rank_master_revision()'
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_guard_gacha_rank()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE rank_status text;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Catalog Gacha Ranks cannot be deleted';
                END IF;
                IF NEW.gacha_id IS DISTINCT FROM OLD.gacha_id
                   OR NEW.rank_master_id IS DISTINCT FROM OLD.rank_master_id THEN
                    RAISE EXCEPTION 'Catalog Gacha Rank ownership is immutable';
                END IF;
                IF OLD.current_video_revision_id IS NULL
                   AND NEW.current_video_revision_id IS NOT NULL
                   AND NEW.revision = OLD.revision
                   AND NEW.first_published_at IS NOT DISTINCT FROM OLD.first_published_at THEN
                    RETURN NEW;
                END IF;
                IF NEW.revision <> OLD.revision + 1 THEN
                    RAISE EXCEPTION 'Catalog Gacha Rank revision must increase by one';
                END IF;
                IF OLD.first_published_at IS NULL
                   AND NEW.first_published_at IS NOT NULL THEN
                    SELECT status INTO rank_status
                    FROM catalog_rank_masters
                    WHERE id = NEW.rank_master_id
                    FOR UPDATE;
                    IF rank_status IS DISTINCT FROM 'active' THEN
                        RAISE EXCEPTION 'Inactive Rank Masters cannot gain publication usage';
                    END IF;
                END IF;
                IF OLD.first_published_at IS NOT NULL
                   AND NEW.first_published_at IS DISTINCT FROM OLD.first_published_at THEN
                    RAISE EXCEPTION 'Catalog Gacha Rank publication usage is immutable';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER catalog_gacha_ranks_guard_mutation '.
            'BEFORE UPDATE OR DELETE ON catalog_gacha_ranks FOR EACH ROW '.
            'EXECUTE FUNCTION v2_catalog_guard_gacha_rank()'
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_validate_gacha_rank_current_video()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE current_owner_id bigint;
            DECLARE current_pointer_id bigint;
            BEGIN
                SELECT current_video_revision_id
                  INTO current_pointer_id
                FROM catalog_gacha_ranks
                WHERE id = NEW.id;
                IF current_pointer_id IS NULL THEN
                    RETURN NULL;
                END IF;
                SELECT gacha_rank_id
                  INTO current_owner_id
                FROM catalog_gacha_rank_video_revisions
                WHERE id = current_pointer_id;
                IF current_owner_id IS DISTINCT FROM NEW.id THEN
                    RAISE EXCEPTION 'Catalog Gacha Rank current Video Revision is invalid';
                END IF;
                RETURN NULL;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE CONSTRAINT TRIGGER catalog_gacha_ranks_current_video_guard '.
            'AFTER INSERT OR UPDATE OF current_video_revision_id ON catalog_gacha_ranks '.
            'DEFERRABLE INITIALLY DEFERRED FOR EACH ROW '.
            'EXECUTE FUNCTION v2_catalog_validate_gacha_rank_current_video()'
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_guard_gacha_rank_video_revision()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE asset_type text;
            BEGIN
                IF TG_OP IN ('UPDATE', 'DELETE') THEN
                    RAISE EXCEPTION 'Catalog Gacha Rank Video Revisions are immutable';
                END IF;
                SELECT media_type INTO asset_type
                FROM catalog_presentation_assets
                WHERE id = NEW.video_asset_id;
                IF asset_type IS DISTINCT FROM 'video' THEN
                    RAISE EXCEPTION 'Catalog Gacha Rank Video Revision requires a video Asset';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER catalog_gacha_rank_video_revisions_guard_mutation '.
            'BEFORE INSERT OR UPDATE OR DELETE ON catalog_gacha_rank_video_revisions '.
            'FOR EACH ROW EXECUTE FUNCTION v2_catalog_guard_gacha_rank_video_revision()'
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_guard_canonical_prize_scope()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE canonical_gacha_id bigint;
            DECLARE canonical_rank_status text;
            DECLARE canonical_video_revision_id bigint;
            BEGIN
                IF TG_OP = 'UPDATE'
                   AND NEW.gacha_rank_id IS DISTINCT FROM OLD.gacha_rank_id THEN
                    RAISE EXCEPTION 'Catalog Prize Gacha Rank is immutable';
                END IF;
                IF NEW.gacha_rank_id IS NOT NULL THEN
                    SELECT master.status
                      INTO canonical_rank_status
                    FROM catalog_gacha_ranks AS gacha_rank
                    JOIN catalog_rank_masters AS master
                      ON master.id = gacha_rank.rank_master_id
                    WHERE gacha_rank.id = NEW.gacha_rank_id
                    FOR UPDATE OF master;
                    SELECT gacha_rank.gacha_id, gacha_rank.current_video_revision_id
                      INTO canonical_gacha_id, canonical_video_revision_id
                    FROM catalog_gacha_ranks AS gacha_rank
                    WHERE gacha_rank.id = NEW.gacha_rank_id
                    FOR UPDATE;
                    IF canonical_gacha_id IS DISTINCT FROM NEW.gacha_id THEN
                        RAISE EXCEPTION 'Cross-Gacha canonical Prize relation is not allowed';
                    END IF;
                    IF canonical_rank_status IS DISTINCT FROM 'active' THEN
                        RAISE EXCEPTION 'Inactive Rank Masters cannot receive Prizes';
                    END IF;
                    IF canonical_video_revision_id IS NULL THEN
                        RAISE EXCEPTION 'Canonical Prizes require a Gacha Rank video';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER catalog_prizes_canonical_scope_guard '.
            'BEFORE INSERT OR UPDATE OF gacha_id, gacha_rank_id ON catalog_prizes '.
            'FOR EACH ROW EXECUTE FUNCTION v2_catalog_guard_canonical_prize_scope()'
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_guard_canonical_version_prize_scope()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE version_gacha_id bigint;
            DECLARE prize_gacha_id bigint;
            DECLARE prize_gacha_rank_id bigint;
            DECLARE canonical_gacha_id bigint;
            BEGIN
                IF NEW.gacha_rank_id IS NULL THEN
                    RETURN NEW;
                END IF;
                SELECT gacha_id INTO version_gacha_id
                FROM catalog_gacha_versions
                WHERE id = NEW.gacha_version_id;
                SELECT gacha_id, gacha_rank_id INTO prize_gacha_id, prize_gacha_rank_id
                FROM catalog_prizes
                WHERE id = NEW.prize_id;
                SELECT gacha_id INTO canonical_gacha_id
                FROM catalog_gacha_ranks
                WHERE id = NEW.gacha_rank_id;
                IF version_gacha_id IS NULL
                   OR version_gacha_id IS DISTINCT FROM prize_gacha_id
                   OR version_gacha_id IS DISTINCT FROM canonical_gacha_id
                   OR prize_gacha_rank_id IS DISTINCT FROM NEW.gacha_rank_id THEN
                    RAISE EXCEPTION 'Cross-Gacha canonical Version Prize relation is not allowed';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER catalog_gacha_version_prizes_canonical_scope_guard '.
            'BEFORE INSERT OR UPDATE OF gacha_version_id, prize_id, gacha_rank_id '.
            'ON catalog_gacha_version_prizes FOR EACH ROW '.
            'EXECUTE FUNCTION v2_catalog_guard_canonical_version_prize_scope()'
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_guard_canonical_draw_revision()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE canonical_gacha_rank_id bigint;
            DECLARE canonical_rank_master_id bigint;
            DECLARE revision_master_id bigint;
            DECLARE video_gacha_rank_id bigint;
            BEGIN
                IF NEW.rank_master_revision_id IS NULL THEN
                    RETURN NEW;
                END IF;
                SELECT prize.gacha_rank_id, gacha_rank.rank_master_id
                  INTO canonical_gacha_rank_id, canonical_rank_master_id
                FROM catalog_gacha_version_prizes relation
                JOIN catalog_prizes prize ON prize.id = relation.prize_id
                JOIN catalog_gacha_ranks gacha_rank ON gacha_rank.id = prize.gacha_rank_id
                WHERE relation.id = NEW.gacha_version_prize_id
                  AND relation.gacha_rank_id = prize.gacha_rank_id;
                SELECT rank_master_id INTO revision_master_id
                FROM catalog_rank_master_revisions
                WHERE id = NEW.rank_master_revision_id;
                SELECT gacha_rank_id INTO video_gacha_rank_id
                FROM catalog_gacha_rank_video_revisions
                WHERE id = NEW.gacha_rank_video_revision_id;
                IF canonical_gacha_rank_id IS NULL
                   OR revision_master_id IS DISTINCT FROM canonical_rank_master_id
                   OR video_gacha_rank_id IS DISTINCT FROM canonical_gacha_rank_id THEN
                    RAISE EXCEPTION 'Canonical Draw presentation Revision ownership is invalid';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER draw_results_canonical_revision_guard '.
            'BEFORE INSERT ON draw_results FOR EACH ROW '.
            'EXECUTE FUNCTION v2_catalog_guard_canonical_draw_revision()'
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_guard_canonical_asset_reference()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF (
                    NEW.is_public IS DISTINCT FROM OLD.is_public
                    OR NEW.archived_at IS DISTINCT FROM OLD.archived_at
                ) AND (
                    EXISTS (
                        SELECT 1 FROM catalog_rank_master_revisions revision
                        WHERE revision.lineup_image_asset_id = OLD.id
                           OR revision.result_image_asset_id = OLD.id
                    )
                    OR EXISTS (
                        SELECT 1 FROM catalog_gacha_rank_video_revisions revision
                        WHERE revision.video_asset_id = OLD.id
                    )
                ) THEN
                    RAISE EXCEPTION 'Canonical presentation Revision Assets remain public and immutable';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER catalog_presentation_assets_canonical_reference_guard '.
            'BEFORE UPDATE OF is_public, archived_at ON catalog_presentation_assets '.
            'FOR EACH ROW EXECUTE FUNCTION v2_catalog_guard_canonical_asset_reference()'
        );

        foreach ([
            'catalog_rank_master_revisions',
            'catalog_gacha_rank_video_revisions',
        ] as $table) {
            DB::statement("REVOKE UPDATE, DELETE, TRUNCATE ON {$table} FROM PUBLIC");
        }
        foreach (['catalog_rank_masters', 'catalog_gacha_ranks'] as $table) {
            DB::statement("REVOKE DELETE, TRUNCATE ON {$table} FROM PUBLIC");
        }
    }

    private function dropConstraintsAndTriggers(): void
    {
        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_presentation_assets_canonical_reference_guard '.
            'ON catalog_presentation_assets'
        );
        DB::statement('DROP FUNCTION IF EXISTS v2_catalog_guard_canonical_asset_reference()');
        DB::statement('DROP TRIGGER IF EXISTS draw_results_canonical_revision_guard ON draw_results');
        DB::statement('DROP FUNCTION IF EXISTS v2_catalog_guard_canonical_draw_revision()');
        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_gacha_version_prizes_canonical_scope_guard '.
            'ON catalog_gacha_version_prizes'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS v2_catalog_guard_canonical_version_prize_scope()'
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_prizes_canonical_scope_guard ON catalog_prizes'
        );
        DB::statement('DROP FUNCTION IF EXISTS v2_catalog_guard_canonical_prize_scope()');
        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_gacha_rank_video_revisions_guard_mutation '.
            'ON catalog_gacha_rank_video_revisions'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS v2_catalog_guard_gacha_rank_video_revision()'
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_gacha_ranks_current_video_guard '.
            'ON catalog_gacha_ranks'
        );
        DB::statement('DROP FUNCTION IF EXISTS v2_catalog_validate_gacha_rank_current_video()');
        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_gacha_ranks_guard_mutation ON catalog_gacha_ranks'
        );
        DB::statement('DROP FUNCTION IF EXISTS v2_catalog_guard_gacha_rank()');
        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_rank_master_revisions_guard_mutation '.
            'ON catalog_rank_master_revisions'
        );
        DB::statement('DROP FUNCTION IF EXISTS v2_catalog_guard_rank_master_revision()');
        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_rank_masters_current_revision_guard '.
            'ON catalog_rank_masters'
        );
        DB::statement('DROP FUNCTION IF EXISTS v2_catalog_validate_rank_master_current_revision()');
        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_rank_masters_guard_mutation ON catalog_rank_masters'
        );
        DB::statement('DROP FUNCTION IF EXISTS v2_catalog_guard_rank_master()');
        DB::statement(
            'ALTER TABLE catalog_gacha_rank_video_revisions DROP CONSTRAINT '.
            'catalog_gacha_rank_video_revision_values_check'
        );
        DB::statement(
            'ALTER TABLE catalog_rank_master_revisions DROP CONSTRAINT '.
            'catalog_rank_master_revision_values_check'
        );
        DB::statement(
            'ALTER TABLE catalog_rank_masters DROP CONSTRAINT catalog_rank_masters_status_check'
        );
    }
};
