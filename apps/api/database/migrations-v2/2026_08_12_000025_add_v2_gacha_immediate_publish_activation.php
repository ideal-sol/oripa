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
            $table->unsignedBigInteger('active_draw_state_id')->nullable()->unique()
                ->after('published_version_id');
        });

        DB::statement(
            'UPDATE catalog_gachas AS g '.
            'SET active_draw_state_id = state.id, '.
            'updated_at = CURRENT_TIMESTAMP '.
            'FROM gacha_draw_states AS state '.
            'WHERE state.gacha_id = g.id AND g.published_version_id IS NOT NULL'
        );

        Schema::table('catalog_gachas', function (Blueprint $table): void {
            $table->foreign('active_draw_state_id')
                ->references('id')->on('gacha_draw_states')->restrictOnDelete();
        });
        Schema::table('gacha_draw_states', function (Blueprint $table): void {
            $table->dropUnique('gacha_draw_states_gacha_id_unique');
            $table->index('gacha_id');
        });

        $this->activationGuards();
    }

    public function down(): void
    {
        DB::statement(
            'DROP TRIGGER IF EXISTS gacha_draw_states_protect_activation '.
            'ON gacha_draw_states'
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS prize_inventories_validate_activation '.
            'ON prize_inventories'
        );
        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_gachas_validate_activation '.
            'ON catalog_gachas'
        );
        DB::statement('DROP FUNCTION IF EXISTS v2_validate_gacha_activation()');

        if (
            DB::table('gacha_draw_states')
                ->select('gacha_id')
                ->groupBy('gacha_id')
                ->havingRaw('COUNT(*) > 1')
                ->exists()
        ) {
            throw new RuntimeException(
                'Cannot roll back Gacha activation after multiple Versions were activated.'
            );
        }

        Schema::table('gacha_draw_states', function (Blueprint $table): void {
            $table->dropIndex(['gacha_id']);
            $table->unique('gacha_id');
        });
        Schema::table('catalog_gachas', function (Blueprint $table): void {
            $table->dropForeign(['active_draw_state_id']);
            $table->dropUnique(['active_draw_state_id']);
            $table->dropColumn('active_draw_state_id');
        });
    }

    private function activationGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_validate_gacha_activation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE version_row catalog_gacha_versions%ROWTYPE;
            DECLARE state_row gacha_draw_states%ROWTYPE;
            DECLARE gacha_row catalog_gachas%ROWTYPE;
            DECLARE relation_version_id bigint;
            BEGIN
                IF TG_TABLE_NAME = 'catalog_gachas' THEN
                    SELECT * INTO gacha_row
                    FROM catalog_gachas
                    WHERE id = NEW.id;
                    IF TG_OP = 'UPDATE'
                       AND (
                           (
                               gacha_row.published_version_id
                                   IS DISTINCT FROM OLD.published_version_id
                           )
                           IS DISTINCT FROM
                           (
                               gacha_row.active_draw_state_id
                                   IS DISTINCT FROM OLD.active_draw_state_id
                           )
                           OR (
                               (
                                   gacha_row.published_version_id
                                       IS DISTINCT FROM OLD.published_version_id
                               )
                               AND gacha_row.revision
                                   IS DISTINCT FROM OLD.revision + 1
                           )
                       ) THEN
                        RAISE EXCEPTION
                            'Public and Draw activation pointers require one Revision update';
                    END IF;
                    IF (gacha_row.published_version_id IS NULL)
                        IS DISTINCT FROM
                            (gacha_row.active_draw_state_id IS NULL) THEN
                        RAISE EXCEPTION
                            'Public and Draw activation pointers must change together';
                    END IF;
                    IF gacha_row.published_version_id IS NULL THEN
                        RETURN NEW;
                    END IF;

                    SELECT * INTO version_row
                    FROM catalog_gacha_versions
                    WHERE id = gacha_row.published_version_id;
                    SELECT * INTO state_row
                    FROM gacha_draw_states
                    WHERE id = gacha_row.active_draw_state_id;

                    IF version_row.id IS NULL
                       OR version_row.gacha_id IS DISTINCT FROM gacha_row.id
                       OR version_row.status::text <> 'published'::text
                       OR version_row.archived_at IS NOT NULL
                       OR version_row.published_at IS NULL
                       OR version_row.published_probability_version_id IS NULL
                       OR state_row.id IS NULL
                       OR state_row.gacha_id IS DISTINCT FROM gacha_row.id
                       OR state_row.gacha_version_id
                            IS DISTINCT FROM version_row.id
                       OR state_row.probability_version_id
                            IS DISTINCT FROM
                                version_row.published_probability_version_id THEN
                        RAISE EXCEPTION
                            'Gacha activation requires matching Published Catalog and Draw pointers';
                    END IF;
                    RETURN NEW;
                END IF;

                IF TG_TABLE_NAME = 'gacha_draw_states' THEN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Gacha Draw State history cannot be deleted';
                    END IF;
                    IF TG_OP = 'UPDATE'
                       AND (
                           NEW.gacha_id IS DISTINCT FROM OLD.gacha_id
                           OR NEW.gacha_version_id
                                IS DISTINCT FROM OLD.gacha_version_id
                           OR NEW.probability_version_id
                                IS DISTINCT FROM OLD.probability_version_id
                       ) THEN
                        RAISE EXCEPTION
                            'Gacha Draw State Catalog identity is immutable';
                    END IF;

                    SELECT * INTO version_row
                    FROM catalog_gacha_versions
                    WHERE id = NEW.gacha_version_id;
                    IF version_row.id IS NULL
                       OR version_row.gacha_id IS DISTINCT FROM NEW.gacha_id
                       OR version_row.status::text <> 'published'::text
                       OR version_row.archived_at IS NOT NULL
                       OR version_row.published_at IS NULL
                       OR version_row.published_probability_version_id
                            IS DISTINCT FROM NEW.probability_version_id THEN
                        RAISE EXCEPTION
                            'Gacha Draw State requires its Published Catalog Snapshot';
                    END IF;
                    RETURN NEW;
                END IF;

                SELECT gacha_version_id INTO relation_version_id
                FROM catalog_gacha_version_prizes
                WHERE id = NEW.gacha_version_prize_id;
                SELECT * INTO state_row
                FROM gacha_draw_states
                WHERE id = NEW.gacha_draw_state_id;
                IF relation_version_id IS DISTINCT FROM state_row.gacha_version_id THEN
                    RAISE EXCEPTION
                        'Prize Inventory must belong to its Gacha Draw State Version';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE CONSTRAINT TRIGGER catalog_gachas_validate_activation '.
            'AFTER INSERT OR UPDATE ON catalog_gachas DEFERRABLE INITIALLY DEFERRED '.
            'FOR EACH ROW '.
            'EXECUTE FUNCTION v2_validate_gacha_activation()'
        );
        DB::statement(
            'CREATE TRIGGER gacha_draw_states_protect_activation '.
            'BEFORE INSERT OR UPDATE OF gacha_id, gacha_version_id, '.
            'probability_version_id OR DELETE ON gacha_draw_states FOR EACH ROW '.
            'EXECUTE FUNCTION v2_validate_gacha_activation()'
        );
        DB::statement(
            'CREATE TRIGGER prize_inventories_validate_activation '.
            'BEFORE INSERT OR UPDATE OF gacha_draw_state_id, '.
            'gacha_version_prize_id ON prize_inventories FOR EACH ROW '.
            'EXECUTE FUNCTION v2_validate_gacha_activation()'
        );
    }
};
