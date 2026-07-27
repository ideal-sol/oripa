<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_categories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->string('code', 64)->unique();
            $table->string('slug', 128)->unique();
            $table->string('display_name', 191);
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestampsTz();
        });

        Schema::create('catalog_tags', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->string('code', 64)->unique();
            $table->string('slug', 128)->unique();
            $table->string('display_name', 191);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestampsTz();
        });

        Schema::create('catalog_ranks', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->string('code', 32)->unique();
            $table->string('display_name', 128);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestampsTz();
        });

        Schema::create('catalog_presentation_assets', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->string('storage_identifier', 512)->unique();
            $table->string('public_path', 512)->unique();
            $table->char('checksum_sha256', 64);
            $table->string('media_type', 16);
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('byte_size');
            $table->string('alt_text', 191)->nullable();
            $table->boolean('is_public')->default(false);
            $table->timestampsTz();
        });

        Schema::create('catalog_rank_assets', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('rank_id')
                ->constrained('catalog_ranks')->restrictOnDelete();
            $table->foreignId('presentation_asset_id')
                ->constrained('catalog_presentation_assets')->restrictOnDelete();
            $table->string('usage_type', 24);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->unique(
                ['rank_id', 'presentation_asset_id', 'usage_type'],
                'catalog_rank_assets_unique'
            );
        });

        Schema::create('catalog_prizes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->string('code', 64)->unique();
            $table->foreignId('rank_id')
                ->constrained('catalog_ranks')->restrictOnDelete();
            $table->foreignId('presentation_asset_id')->nullable()
                ->constrained('catalog_presentation_assets')->restrictOnDelete();
            $table->string('display_name', 191);
            $table->text('description')->nullable();
            $table->bigInteger('display_price');
            $table->bigInteger('exchange_points');
            $table->boolean('is_visible')->default(true);
            $table->timestampsTz();
        });

        Schema::create('catalog_gachas', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->string('code', 64)->unique();
            $table->string('slug', 128)->unique();
            $table->foreignId('category_id')
                ->constrained('catalog_categories')->restrictOnDelete();
            $table->string('state', 16)->default('draft');
            $table->unsignedBigInteger('sold_count')->default(0);
            $table->unsignedBigInteger('published_version_id')->nullable()->unique();
            $table->timestampsTz();
        });

        Schema::create('catalog_gacha_tags', function (Blueprint $table): void {
            $table->foreignId('gacha_id')
                ->constrained('catalog_gachas')->restrictOnDelete();
            $table->foreignId('tag_id')
                ->constrained('catalog_tags')->restrictOnDelete();
            $table->primary(['gacha_id', 'tag_id']);
        });

        Schema::create('catalog_gacha_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('gacha_id')
                ->constrained('catalog_gachas')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status', 16)->default('draft');
            $table->string('title', 191);
            $table->text('description')->nullable();
            $table->text('notices')->nullable();
            $table->bigInteger('price_points');
            $table->unsignedBigInteger('total_count');
            $table->foreignId('presentation_asset_id')->nullable()
                ->constrained('catalog_presentation_assets')->restrictOnDelete();
            $table->unsignedBigInteger('published_probability_version_id')->nullable()->unique();
            $table->timestampTz('publish_start_at');
            $table->timestampTz('publish_end_at')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->unique(['gacha_id', 'version_number']);
        });

        Schema::create('catalog_gacha_version_prizes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('gacha_version_id')
                ->constrained('catalog_gacha_versions')->restrictOnDelete();
            $table->foreignId('prize_id')
                ->constrained('catalog_prizes')->restrictOnDelete();
            $table->unsignedBigInteger('initial_inventory');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->unique(['gacha_version_id', 'prize_id']);
        });

        Schema::create('catalog_probability_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('gacha_version_id')
                ->constrained('catalog_gacha_versions')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status', 16)->default('draft');
            $table->char('snapshot_sha256', 64);
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->unique(['gacha_version_id', 'version_number']);
        });

        Schema::create('catalog_probability_stages', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('probability_version_id')
                ->constrained('catalog_probability_versions')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('display_name', 128);
            $table->string('condition_type', 24);
            $table->unsignedBigInteger('min_draw_number');
            $table->unsignedBigInteger('max_draw_number')->nullable();
            $table->unsignedInteger('sort_order');
            $table->timestampsTz();
            $table->unique(['probability_version_id', 'code']);
            $table->unique(['probability_version_id', 'sort_order']);
        });

        Schema::create('catalog_probability_entries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('probability_stage_id')
                ->constrained('catalog_probability_stages')->restrictOnDelete();
            $table->string('result_type', 24);
            $table->foreignId('gacha_version_prize_id')->nullable()
                ->constrained('catalog_gacha_version_prizes')->restrictOnDelete();
            $table->bigInteger('point_amount')->nullable();
            $table->unsignedInteger('probability_ppm');
            $table->unsignedInteger('sort_order');
            $table->timestampsTz();
            $table->unique(['probability_stage_id', 'sort_order']);
        });

        Schema::create('catalog_minimum_guarantees', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('probability_stage_id')->unique()
                ->constrained('catalog_probability_stages')->restrictOnDelete();
            $table->string('result_type', 24);
            $table->foreignId('gacha_version_prize_id')->nullable()
                ->constrained('catalog_gacha_version_prizes')->restrictOnDelete();
            $table->bigInteger('point_amount')->nullable();
            $table->unsignedInteger('probability_ppm');
            $table->timestampsTz();
        });

        Schema::create('catalog_import_runs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->char('source_baseline_sha', 40);
            $table->string('import_type', 32);
            $table->char('manifest_checksum', 64)->unique();
            $table->string('status', 16);
            $table->string('tool_version', 32);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });

        Schema::table('catalog_gacha_versions', function (Blueprint $table): void {
            $table->foreign('published_probability_version_id')
                ->references('id')->on('catalog_probability_versions')
                ->restrictOnDelete();
        });
        Schema::table('catalog_gachas', function (Blueprint $table): void {
            $table->foreign('published_version_id')
                ->references('id')->on('catalog_gacha_versions')
                ->restrictOnDelete();
        });

        $this->constraints();
        $this->publishedGuards();
    }

    public function down(): void
    {
        Schema::table('catalog_gachas', function (Blueprint $table): void {
            $table->dropForeign(['published_version_id']);
        });
        Schema::table('catalog_gacha_versions', function (Blueprint $table): void {
            $table->dropForeign(['published_probability_version_id']);
        });
        foreach ([
            'catalog_import_runs',
            'catalog_minimum_guarantees',
            'catalog_probability_entries',
            'catalog_probability_stages',
            'catalog_probability_versions',
            'catalog_gacha_version_prizes',
            'catalog_gacha_versions',
            'catalog_gacha_tags',
            'catalog_gachas',
            'catalog_prizes',
            'catalog_rank_assets',
            'catalog_presentation_assets',
            'catalog_ranks',
            'catalog_tags',
            'catalog_categories',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP FUNCTION IF EXISTS v2_catalog_validate_probability_publish() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS v2_catalog_validate_gacha_publish() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS v2_catalog_protect_published() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS v2_catalog_validate_entry_target() CASCADE');
    }

    private function constraints(): void
    {
        DB::statement("ALTER TABLE catalog_presentation_assets ADD CONSTRAINT catalog_asset_values_check CHECK (checksum_sha256 ~ '^[0-9a-f]{64}$' AND media_type::text = ANY (ARRAY['image'::text,'video'::text]) AND public_path ~ '^/' AND public_path !~ '^[a-zA-Z][a-zA-Z0-9+.-]*://' AND byte_size >= 0)");
        DB::statement("ALTER TABLE catalog_rank_assets ADD CONSTRAINT catalog_rank_asset_usage_check CHECK (usage_type::text = ANY (ARRAY['image'::text,'video'::text,'result_image'::text]))");
        DB::statement('ALTER TABLE catalog_prizes ADD CONSTRAINT catalog_prize_values_check CHECK (display_price >= 0 AND exchange_points >= 0)');
        DB::statement("ALTER TABLE catalog_gachas ADD CONSTRAINT catalog_gacha_state_check CHECK (state::text = ANY (ARRAY['draft'::text,'active'::text,'disabled'::text]) AND sold_count >= 0)");
        DB::statement("ALTER TABLE catalog_gacha_versions ADD CONSTRAINT catalog_gacha_version_values_check CHECK (version_number > 0 AND status::text = ANY (ARRAY['draft'::text,'published'::text]) AND price_points > 0 AND total_count > 0 AND (publish_end_at IS NULL OR publish_end_at > publish_start_at))");
        DB::statement("ALTER TABLE catalog_probability_versions ADD CONSTRAINT catalog_probability_version_values_check CHECK (version_number > 0 AND status::text = ANY (ARRAY['draft'::text,'published'::text]) AND snapshot_sha256 ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE catalog_probability_stages ADD CONSTRAINT catalog_probability_stage_values_check CHECK (condition_type::text = 'sold_count'::text AND min_draw_number > 0 AND (max_draw_number IS NULL OR max_draw_number >= min_draw_number))");
        DB::statement("ALTER TABLE catalog_probability_entries ADD CONSTRAINT catalog_probability_entry_values_check CHECK (result_type::text = ANY (ARRAY['prize'::text,'point_back'::text]) AND probability_ppm <= 1000000 AND ((result_type = 'prize' AND gacha_version_prize_id IS NOT NULL AND point_amount IS NULL) OR (result_type = 'point_back' AND gacha_version_prize_id IS NULL AND point_amount IS NOT NULL AND point_amount >= 0)))");
        DB::statement("ALTER TABLE catalog_minimum_guarantees ADD CONSTRAINT catalog_minimum_guarantee_values_check CHECK (result_type::text = ANY (ARRAY['prize'::text,'point_back'::text]) AND probability_ppm <= 1000000 AND ((result_type = 'prize' AND gacha_version_prize_id IS NOT NULL AND point_amount IS NULL) OR (result_type = 'point_back' AND gacha_version_prize_id IS NULL AND point_amount IS NOT NULL AND point_amount >= 0)))");
        DB::statement("ALTER TABLE catalog_import_runs ADD CONSTRAINT catalog_import_run_values_check CHECK (source_baseline_sha ~ '^[0-9a-f]{40}$' AND manifest_checksum ~ '^[0-9a-f]{64}$' AND import_type = 'catalog_fixture' AND status::text = ANY (ARRAY['running'::text,'completed'::text,'failed'::text]))");
    }

    private function publishedGuards(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_validate_entry_target()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE stage_version_id bigint;
            DECLARE relation_version_id bigint;
            BEGIN
                SELECT pv.gacha_version_id INTO stage_version_id
                FROM catalog_probability_stages ps
                JOIN catalog_probability_versions pv
                  ON pv.id = ps.probability_version_id
                WHERE ps.id = NEW.probability_stage_id;
                IF NEW.gacha_version_prize_id IS NOT NULL THEN
                    SELECT gvp.gacha_version_id INTO relation_version_id
                    FROM catalog_gacha_version_prizes gvp
                    WHERE gvp.id = NEW.gacha_version_prize_id;
                    IF relation_version_id IS DISTINCT FROM stage_version_id THEN
                        RAISE EXCEPTION 'Catalog probability target belongs to another Gacha Version';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$
            SQL);
        DB::statement('CREATE TRIGGER catalog_probability_entries_validate_target BEFORE INSERT OR UPDATE ON catalog_probability_entries FOR EACH ROW EXECUTE FUNCTION v2_catalog_validate_entry_target()');
        DB::statement('CREATE TRIGGER catalog_minimum_guarantees_validate_target BEFORE INSERT OR UPDATE ON catalog_minimum_guarantees FOR EACH ROW EXECUTE FUNCTION v2_catalog_validate_entry_target()');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_validate_probability_publish()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE invalid_stage_count bigint;
            DECLARE stage_count bigint;
            BEGIN
                IF OLD.status = 'published' THEN
                    RAISE EXCEPTION 'Published Probability Version is immutable';
                END IF;
                IF NEW.status = 'published' THEN
                    SELECT COUNT(*) INTO stage_count
                    FROM catalog_probability_stages
                    WHERE probability_version_id = NEW.id;
                    IF stage_count = 0 THEN
                        RAISE EXCEPTION 'Published Probability Version requires a Stage';
                    END IF;
                    SELECT COUNT(*) INTO invalid_stage_count
                    FROM catalog_probability_stages ps
                    LEFT JOIN LATERAL (
                        SELECT COALESCE(SUM(pe.probability_ppm), 0) AS entry_total
                        FROM catalog_probability_entries pe
                        WHERE pe.probability_stage_id = ps.id
                    ) entries ON true
                    LEFT JOIN catalog_minimum_guarantees mg
                      ON mg.probability_stage_id = ps.id
                    WHERE ps.probability_version_id = NEW.id
                      AND (
                        mg.id IS NULL
                        OR entries.entry_total + mg.probability_ppm <> 1000000
                      );
                    IF invalid_stage_count <> 0 THEN
                        RAISE EXCEPTION 'Each Probability Stage must total 1000000 ppm';
                    END IF;
                    NEW.published_at := COALESCE(NEW.published_at, CURRENT_TIMESTAMP);
                END IF;
                RETURN NEW;
            END;
            $$
            SQL);
        DB::statement('CREATE TRIGGER catalog_probability_versions_validate_publish BEFORE UPDATE OF status ON catalog_probability_versions FOR EACH ROW EXECUTE FUNCTION v2_catalog_validate_probability_publish()');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_validate_gacha_publish()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE probability_status text;
            DECLARE probability_gacha_version_id bigint;
            BEGIN
                IF OLD.status = 'published' THEN
                    RAISE EXCEPTION 'Published Gacha Version is immutable';
                END IF;
                IF NEW.status = 'published' THEN
                    SELECT status, gacha_version_id
                      INTO probability_status, probability_gacha_version_id
                    FROM catalog_probability_versions
                    WHERE id = NEW.published_probability_version_id;
                    IF probability_status IS DISTINCT FROM 'published'
                       OR probability_gacha_version_id IS DISTINCT FROM NEW.id
                    THEN
                        RAISE EXCEPTION 'Published Gacha Version requires its Published Probability Version';
                    END IF;
                    NEW.published_at := COALESCE(NEW.published_at, CURRENT_TIMESTAMP);
                END IF;
                RETURN NEW;
            END;
            $$
            SQL);
        DB::statement('CREATE TRIGGER catalog_gacha_versions_validate_publish BEFORE UPDATE OF status ON catalog_gacha_versions FOR EACH ROW EXECUTE FUNCTION v2_catalog_validate_gacha_publish()');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_protect_published()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE parent_status text;
            DECLARE parent_id bigint;
            BEGIN
                IF TG_TABLE_NAME = 'catalog_gacha_versions' THEN
                    IF TG_OP = 'INSERT' AND NEW.status <> 'draft' THEN
                        RAISE EXCEPTION 'Gacha Version must be inserted as Draft';
                    ELSIF TG_OP <> 'INSERT' AND OLD.status = 'published' THEN
                        RAISE EXCEPTION 'Published Gacha Version is immutable';
                    END IF;
                    IF TG_OP = 'DELETE' THEN RETURN OLD; ELSE RETURN NEW; END IF;
                ELSIF TG_TABLE_NAME = 'catalog_probability_versions' THEN
                    IF TG_OP = 'INSERT' AND NEW.status <> 'draft' THEN
                        RAISE EXCEPTION 'Probability Version must be inserted as Draft';
                    ELSIF TG_OP <> 'INSERT' AND OLD.status = 'published' THEN
                        RAISE EXCEPTION 'Published Probability Version is immutable';
                    END IF;
                    IF TG_OP = 'DELETE' THEN RETURN OLD; ELSE RETURN NEW; END IF;
                ELSIF TG_TABLE_NAME = 'catalog_gacha_version_prizes' THEN
                    parent_id := CASE WHEN TG_OP = 'DELETE'
                        THEN OLD.gacha_version_id ELSE NEW.gacha_version_id END;
                    SELECT status INTO parent_status FROM catalog_gacha_versions WHERE id = parent_id;
                ELSIF TG_TABLE_NAME = 'catalog_probability_stages' THEN
                    parent_id := CASE WHEN TG_OP = 'DELETE'
                        THEN OLD.probability_version_id ELSE NEW.probability_version_id END;
                    SELECT status INTO parent_status FROM catalog_probability_versions WHERE id = parent_id;
                ELSE
                    parent_id := CASE WHEN TG_OP = 'DELETE'
                        THEN OLD.probability_stage_id ELSE NEW.probability_stage_id END;
                    SELECT pv.status INTO parent_status
                    FROM catalog_probability_stages ps
                    JOIN catalog_probability_versions pv ON pv.id = ps.probability_version_id
                    WHERE ps.id = parent_id;
                END IF;
                IF parent_status = 'published' THEN
                    RAISE EXCEPTION 'Published Catalog Version children are immutable';
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; ELSE RETURN NEW; END IF;
            END;
            $$
            SQL);
        foreach ([
            'catalog_gacha_versions',
            'catalog_probability_versions',
            'catalog_gacha_version_prizes',
            'catalog_probability_stages',
            'catalog_probability_entries',
            'catalog_minimum_guarantees',
        ] as $table) {
            DB::statement("CREATE TRIGGER {$table}_protect_published BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION v2_catalog_protect_published()");
        }
    }
};
