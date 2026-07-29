<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_probability_versions', function (Blueprint $table): void {
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampTz('archived_at')->nullable();
            $table->foreignId('cloned_from_probability_version_id')->nullable()
                ->constrained('catalog_probability_versions')->restrictOnDelete();
            $table->index(['gacha_version_id', 'archived_at']);
        });

        DB::statement(
            'ALTER TABLE catalog_probability_versions '.
            'ADD CONSTRAINT catalog_probability_versions_draft_management_check '.
            "CHECK (revision::numeric > 0::numeric AND ".
            "(archived_at IS NULL OR status::text = 'draft'::text))"
        );
        DB::statement(
            'CREATE UNIQUE INDEX catalog_probability_entries_stage_prize_unique '.
            'ON catalog_probability_entries (probability_stage_id, gacha_version_prize_id) '.
            'WHERE gacha_version_prize_id IS NOT NULL'
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_protect_probability_draft_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Catalog Probability Version records cannot be deleted';
                END IF;
                IF OLD.status = 'published' THEN
                    RAISE EXCEPTION 'Published Probability Version is immutable';
                END IF;
                IF OLD.archived_at IS NOT NULL THEN
                    RAISE EXCEPTION 'Archived Probability Draft Version is immutable';
                END IF;
                IF NEW.gacha_version_id IS DISTINCT FROM OLD.gacha_version_id
                   OR NEW.version_number IS DISTINCT FROM OLD.version_number
                   OR NEW.cloned_from_probability_version_id
                        IS DISTINCT FROM OLD.cloned_from_probability_version_id THEN
                    RAISE EXCEPTION 'Probability Draft Version identity is immutable';
                END IF;
                IF NEW.revision <> OLD.revision + 1 THEN
                    RAISE EXCEPTION 'Probability Draft Version revision must increase by one';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER catalog_probability_versions_protect_draft_mutation '.
            'BEFORE UPDATE OR DELETE ON catalog_probability_versions FOR EACH ROW '.
            'EXECUTE FUNCTION v2_catalog_protect_probability_draft_mutation()'
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_protect_probability_draft_child()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE old_parent_version_id bigint;
            DECLARE new_parent_version_id bigint;
            DECLARE old_parent_status text;
            DECLARE new_parent_status text;
            DECLARE old_parent_archived_at timestamptz;
            DECLARE new_parent_archived_at timestamptz;
            BEGIN
                IF TG_TABLE_NAME = 'catalog_probability_stages' THEN
                    IF TG_OP <> 'INSERT' THEN
                        old_parent_version_id := OLD.probability_version_id;
                    END IF;
                    IF TG_OP <> 'DELETE' THEN
                        new_parent_version_id := NEW.probability_version_id;
                    END IF;
                ELSE
                    IF TG_OP <> 'INSERT' THEN
                        SELECT probability_version_id INTO old_parent_version_id
                        FROM catalog_probability_stages
                        WHERE id = OLD.probability_stage_id;
                    END IF;
                    IF TG_OP <> 'DELETE' THEN
                        SELECT probability_version_id INTO new_parent_version_id
                        FROM catalog_probability_stages
                        WHERE id = NEW.probability_stage_id;
                    END IF;
                END IF;

                IF old_parent_version_id IS NOT NULL THEN
                    SELECT status, archived_at
                      INTO old_parent_status, old_parent_archived_at
                    FROM catalog_probability_versions
                    WHERE id = old_parent_version_id;

                    IF old_parent_status IS DISTINCT FROM 'draft'
                       OR old_parent_archived_at IS NOT NULL THEN
                        RAISE EXCEPTION 'Only active Draft Probability Version children are mutable';
                    END IF;
                END IF;

                IF new_parent_version_id IS NOT NULL THEN
                    SELECT status, archived_at
                      INTO new_parent_status, new_parent_archived_at
                    FROM catalog_probability_versions
                    WHERE id = new_parent_version_id;

                    IF new_parent_status IS DISTINCT FROM 'draft'
                       OR new_parent_archived_at IS NOT NULL THEN
                        RAISE EXCEPTION 'Only active Draft Probability Version children are mutable';
                    END IF;
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        foreach ([
            'catalog_probability_stages',
            'catalog_probability_entries',
            'catalog_minimum_guarantees',
        ] as $table) {
            DB::statement(
                "CREATE TRIGGER {$table}_protect_draft_mutation ".
                "BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW ".
                'EXECUTE FUNCTION v2_catalog_protect_probability_draft_child()'
            );
        }

    }

    public function down(): void
    {
        foreach ([
            'catalog_minimum_guarantees',
            'catalog_probability_entries',
            'catalog_probability_stages',
        ] as $table) {
            DB::statement(
                "DROP TRIGGER IF EXISTS {$table}_protect_draft_mutation ON {$table}"
            );
        }
        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_probability_versions_protect_draft_mutation '.
            'ON catalog_probability_versions'
        );
        DB::statement('DROP FUNCTION IF EXISTS v2_catalog_protect_probability_draft_child()');
        DB::statement('DROP FUNCTION IF EXISTS v2_catalog_protect_probability_draft_mutation()');
        DB::statement(
            'DROP INDEX IF EXISTS catalog_probability_entries_stage_prize_unique'
        );
        DB::statement(
            'ALTER TABLE catalog_probability_versions '.
            'DROP CONSTRAINT IF EXISTS catalog_probability_versions_draft_management_check'
        );

        Schema::table('catalog_probability_versions', function (Blueprint $table): void {
            $table->dropIndex(['gacha_version_id', 'archived_at']);
            $table->dropConstrainedForeignId('cloned_from_probability_version_id');
            $table->dropColumn(['revision', 'archived_at']);
        });
    }
};
