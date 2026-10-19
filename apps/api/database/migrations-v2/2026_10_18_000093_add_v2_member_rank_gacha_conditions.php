<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE catalog_gacha_versions
                ADD COLUMN member_rank_mode varchar(16) NOT NULL DEFAULT 'none',
                ADD COLUMN member_rank_level smallint,
                ADD CONSTRAINT catalog_gacha_versions_member_rank_condition_check CHECK (
                    (member_rank_mode = 'none' AND member_rank_level IS NULL)
                    OR (member_rank_mode::text = ANY (ARRAY['at_least', 'only', 'at_most']::text[])
                        AND member_rank_level IS NOT NULL AND member_rank_level BETWEEN 1 AND 7)
                );
            ALTER TABLE draw_requests ADD COLUMN member_rank_level smallint,
                ADD CONSTRAINT draw_requests_member_rank_level_check CHECK (member_rank_level BETWEEN 1 AND 7),
                ADD CONSTRAINT draw_requests_qa_member_rank_check CHECK (NOT is_qa_draw OR member_rank_level IS NULL);

            CREATE OR REPLACE FUNCTION v2_catalog_guard_member_rank_condition() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE gacha catalog_gachas%ROWTYPE;
            DECLARE published catalog_gacha_versions%ROWTYPE;
            BEGIN
                SELECT * INTO STRICT gacha FROM catalog_gachas WHERE id = NEW.gacha_id;
                IF gacha.gacha_type = 'signup_once' AND NEW.member_rank_mode <> 'none' THEN
                    RAISE EXCEPTION 'Signup Gacha cannot require a member rank';
                END IF;
                IF gacha.first_published_at IS NOT NULL THEN
                    SELECT * INTO published FROM catalog_gacha_versions
                        WHERE gacha_id = gacha.id AND published_at IS NOT NULL ORDER BY version_number LIMIT 1;
                    IF published.id IS NULL OR NEW.member_rank_mode IS DISTINCT FROM published.member_rank_mode
                        OR NEW.member_rank_level IS DISTINCT FROM published.member_rank_level THEN
                        RAISE EXCEPTION 'Published Gacha member rank conditions are immutable';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER catalog_gacha_versions_member_rank_guard BEFORE INSERT OR UPDATE ON catalog_gacha_versions
                FOR EACH ROW EXECUTE FUNCTION v2_catalog_guard_member_rank_condition();

            CREATE OR REPLACE FUNCTION v2_draw_guard_member_rank_snapshot() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'TRUNCATE' THEN
                    RAISE EXCEPTION 'Draw member rank snapshots are immutable';
                END IF;
                IF TG_OP = 'DELETE' THEN
                    IF OLD.member_rank_level IS NOT NULL THEN
                        RAISE EXCEPTION 'Draw member rank snapshots are immutable';
                    END IF;
                    RETURN OLD;
                END IF;
                IF NEW.member_rank_level IS DISTINCT FROM OLD.member_rank_level
                    OR (OLD.member_rank_level IS NOT NULL AND (
                        NEW.user_id IS DISTINCT FROM OLD.user_id OR NEW.created_at IS DISTINCT FROM OLD.created_at
                        OR NEW.gacha_version_id IS DISTINCT FROM OLD.gacha_version_id
                        OR NEW.is_qa_draw IS DISTINCT FROM OLD.is_qa_draw)) THEN
                    RAISE EXCEPTION 'Draw member rank snapshots are immutable';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER draw_requests_member_rank_snapshot_guard BEFORE UPDATE OR DELETE ON draw_requests
                FOR EACH ROW EXECUTE FUNCTION v2_draw_guard_member_rank_snapshot();
            CREATE TRIGGER draw_requests_member_rank_snapshot_truncate_guard BEFORE TRUNCATE ON draw_requests
                FOR EACH STATEMENT EXECUTE FUNCTION v2_draw_guard_member_rank_snapshot();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Member rank Gacha conditions and Draw snapshots are forward-only.');
    }
};
