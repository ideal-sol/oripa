<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
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
                       OR (kind = 'signup_once' AND (NEW.audience_code <> 'all_users' OR NEW.first_time_eligible_days <> 7))
                       OR (kind = 'login_daily' AND (NEW.daily_draw_limit <> 1 OR NEW.minimum_exchange_points IS NULL
                           OR NEW.first_time_eligible_days NOT BETWEEN 1 AND 365))
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
            CREATE OR REPLACE FUNCTION v2_catalog_guard_purchase_condition() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE gacha catalog_gachas%ROWTYPE;
            DECLARE published catalog_gacha_versions%ROWTYPE;
            BEGIN
                SELECT * INTO STRICT gacha FROM catalog_gachas WHERE id = NEW.gacha_id;
                IF gacha.gacha_type = 'signup_once' AND (NEW.purchase_count_mode <> 'none' OR NEW.purchase_amount_mode <> 'none') THEN
                    RAISE EXCEPTION 'Signup Gacha cannot have purchase conditions';
                END IF;
                IF gacha.first_published_at IS NOT NULL THEN
                    SELECT * INTO published FROM catalog_gacha_versions
                        WHERE gacha_id = gacha.id AND published_at IS NOT NULL ORDER BY version_number LIMIT 1;
                    IF published.id IS NULL OR ROW(NEW.purchase_count_mode, NEW.purchase_count_value, NEW.purchase_amount_mode, NEW.purchase_amount_value)
                        IS DISTINCT FROM ROW(published.purchase_count_mode, published.purchase_count_value, published.purchase_amount_mode, published.purchase_amount_value) THEN
                        RAISE EXCEPTION 'Published Gacha purchase conditions are immutable';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$;
            SQL);
    }

    public function down(): void
    {
        throw new LogicException('Login Gacha eligibility requires a forward-only correction.');
    }
};
