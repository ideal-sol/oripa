<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE member_rank_settings (
                id bigint PRIMARY KEY CHECK (id = 1),
                public_id uuid NOT NULL UNIQUE,
                enabled boolean NOT NULL DEFAULT false,
                activated_at timestamptz,
                revision bigint NOT NULL DEFAULT 1 CHECK (revision > 0),
                CHECK (enabled = (activated_at IS NOT NULL))
            );
            CREATE TABLE member_rank_setting_versions (
                revision bigint PRIMARY KEY CHECK (revision > 0),
                public_id uuid NOT NULL UNIQUE,
                action varchar(16) NOT NULL CHECK (action::text = ANY (ARRAY['initial', 'schedule', 'cancel']::text[])),
                effective_at timestamptz NOT NULL,
                saved_at timestamptz NOT NULL,
                saved_by_admin_id bigint REFERENCES admins(id) ON DELETE RESTRICT,
                before_revision bigint REFERENCES member_rank_setting_versions(revision) ON DELETE RESTRICT,
                default_skip_count smallint NOT NULL CHECK (default_skip_count BETWEEN 0 AND 12),
                CHECK ((action = 'initial' AND revision = 1 AND before_revision IS NULL AND saved_by_admin_id IS NULL)
                    OR (action <> 'initial' AND revision > 1 AND before_revision < revision AND before_revision IS NOT NULL AND saved_by_admin_id IS NOT NULL)),
                CHECK (action = 'initial' OR effective_at = ((date_trunc('month', saved_at AT TIME ZONE 'Asia/Tokyo') + interval '1 month') AT TIME ZONE 'Asia/Tokyo'))
            );
            CREATE INDEX member_rank_setting_versions_effective ON member_rank_setting_versions (effective_at, revision);
            CREATE TABLE member_rank_setting_values (
                version_revision bigint NOT NULL REFERENCES member_rank_setting_versions(revision) ON DELETE RESTRICT,
                level smallint NOT NULL CHECK (level BETWEEN 1 AND 7),
                reach_line bigint,
                maintain_line bigint,
                promotion_bonus bigint NOT NULL CHECK (promotion_bonus BETWEEN 0 AND 1000000),
                purchase_bonus_rate_bp integer NOT NULL CHECK (purchase_bonus_rate_bp BETWEEN 0 AND 10000),
                PRIMARY KEY (version_revision, level),
                CHECK ((level = 1 AND reach_line IS NULL AND maintain_line IS NULL AND promotion_bonus = 0 AND purchase_bonus_rate_bp = 0)
                    OR (level > 1 AND reach_line IS NOT NULL AND maintain_line IS NOT NULL
                        AND reach_line BETWEEN 1 AND 9007199254740991 AND maintain_line BETWEEN 0 AND reach_line))
            );
            CREATE OR REPLACE FUNCTION v2_member_rank_settings_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'member rank setting history is immutable';
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER v2_member_rank_versions_immutable BEFORE UPDATE OR DELETE ON member_rank_setting_versions
                FOR EACH ROW EXECUTE FUNCTION v2_member_rank_settings_immutable();
            CREATE TRIGGER v2_member_rank_values_immutable BEFORE UPDATE OR DELETE ON member_rank_setting_values
                FOR EACH ROW EXECUTE FUNCTION v2_member_rank_settings_immutable();
            CREATE OR REPLACE FUNCTION v2_member_rank_activation_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' OR NEW.id IS DISTINCT FROM OLD.id OR NEW.public_id IS DISTINCT FROM OLD.public_id
                    OR (OLD.enabled AND (NOT NEW.enabled OR NEW.activated_at IS DISTINCT FROM OLD.activated_at)) THEN
                    RAISE EXCEPTION 'member rank activation is irreversible';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER v2_member_rank_activation_guard BEFORE UPDATE OR DELETE ON member_rank_settings
                FOR EACH ROW EXECUTE FUNCTION v2_member_rank_activation_guard();
            CREATE OR REPLACE FUNCTION v2_member_rank_settings_complete() RETURNS trigger AS $$
            DECLARE target_revision bigint;
            BEGIN
                IF TG_TABLE_NAME = 'member_rank_setting_versions' THEN
                    target_revision := NEW.revision;
                ELSE
                    target_revision := NEW.version_revision;
                END IF;
                IF (SELECT count(*) FROM member_rank_setting_values WHERE version_revision = target_revision) <> 7
                    OR EXISTS (
                        SELECT 1 FROM member_rank_setting_values lower_rank
                        JOIN member_rank_setting_values upper_rank ON upper_rank.version_revision = lower_rank.version_revision
                            AND upper_rank.level = lower_rank.level + 1
                        WHERE lower_rank.version_revision = target_revision AND lower_rank.level > 1
                            AND (upper_rank.reach_line <= lower_rank.reach_line OR upper_rank.maintain_line < lower_rank.maintain_line)
                    ) THEN
                    RAISE EXCEPTION 'member rank settings require seven ordered levels';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE CONSTRAINT TRIGGER v2_member_rank_versions_complete AFTER INSERT ON member_rank_setting_versions
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION v2_member_rank_settings_complete();
            CREATE CONSTRAINT TRIGGER v2_member_rank_values_complete AFTER INSERT ON member_rank_setting_values
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION v2_member_rank_settings_complete();
            SQL);
        DB::table('member_rank_settings')->insert(['id' => 1, 'public_id' => (string) Str::uuid7()]);
        DB::table('member_rank_setting_versions')->insert([
            'revision' => 1, 'public_id' => (string) Str::uuid7(), 'action' => 'initial',
            'effective_at' => '1970-01-01 00:00:00+09:00', 'saved_at' => now(),
            'default_skip_count' => 1,
        ]);
        foreach ([
            [null, null, 0, 0], [10000, 5000, 500, 50], [30000, 20000, 2000, 100],
            [100000, 70000, 5000, 150], [300000, 200000, 20000, 200],
            [700000, 500000, 50000, 300], [1500000, 1000000, 100000, 400],
        ] as $index => $values) {
            DB::table('member_rank_setting_values')->insert([
                'version_revision' => 1, 'level' => $index + 1,
                'reach_line' => $values[0], 'maintain_line' => $values[1],
                'promotion_bonus' => $values[2], 'purchase_bonus_rate_bp' => $values[3],
            ]);
        }
    }

    public function down(): void
    {
        throw new LogicException('Member rank settings history requires a forward-only migration.');
    }
};
