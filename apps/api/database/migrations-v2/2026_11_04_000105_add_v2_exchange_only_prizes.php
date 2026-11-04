<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['content_banners', 'catalog_prizes', 'catalog_gacha_version_prizes'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->boolean('exchange_only')->default(false);
            });
        }
        foreach (['catalog_prizes', 'catalog_gacha_version_prizes'] as $name) {
            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_exchange_only_check CHECK (NOT exchange_only OR (NOT shipping_only AND exchange_points >= 1))");
        }
        Schema::table('user_prizes', function (Blueprint $table): void {
            $table->boolean('exchange_only_snapshot')->default(false);
        });
        DB::statement("ALTER TABLE user_prizes ADD CONSTRAINT user_prizes_exchange_only_check CHECK (NOT exchange_only_snapshot OR (NOT shipping_only_snapshot AND exchange_point_snapshot >= 1 AND status::text NOT IN ('shipping_requested', 'packing', 'shipped', 'delivered', 'return_requested', 'returned')))");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_protect_user_prize_ownership()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' OR
                   (NEW.public_id, NEW.user_id, NEW.draw_result_id, NEW.gacha_version_prize_id,
                    NEW.acquired_at, NEW.exchange_point_snapshot, NEW.storage_expires_at,
                    NEW.shipping_only_snapshot, NEW.exchange_only_snapshot)
                   IS DISTINCT FROM
                   (OLD.public_id, OLD.user_id, OLD.draw_result_id, OLD.gacha_version_prize_id,
                    OLD.acquired_at, OLD.exchange_point_snapshot, OLD.storage_expires_at,
                    OLD.shipping_only_snapshot, OLD.exchange_only_snapshot)
                THEN
                    RAISE EXCEPTION 'V2 user prize ownership and snapshots are immutable';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE OR REPLACE FUNCTION v2_reject_exchange_only_shipping_item()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM user_prizes WHERE id = NEW.user_prize_id AND exchange_only_snapshot) THEN
                    RAISE EXCEPTION 'Exchange-only prizes cannot enter shipping requests';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER v2_exchange_only_shipping_item_guard
            BEFORE INSERT OR UPDATE ON shipping_request_items
            FOR EACH ROW EXECUTE FUNCTION v2_reject_exchange_only_shipping_item();
            CREATE OR REPLACE FUNCTION v2_protect_published_exchange_only()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.exchange_only IS DISTINCT FROM OLD.exchange_only AND EXISTS (
                    SELECT 1 FROM catalog_gachas WHERE id = OLD.gacha_id AND first_published_at IS NOT NULL
                ) THEN
                    RAISE EXCEPTION 'Published prize classification is immutable';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER v2_published_exchange_only_guard
            BEFORE UPDATE ON catalog_prizes
            FOR EACH ROW EXECUTE FUNCTION v2_protect_published_exchange_only();
            CREATE OR REPLACE FUNCTION v2_protect_version_exchange_only()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.exchange_only IS DISTINCT FROM OLD.exchange_only AND EXISTS (
                    SELECT 1 FROM catalog_gacha_versions version
                    JOIN catalog_gachas gacha ON gacha.id = version.gacha_id
                    WHERE version.id = OLD.gacha_version_id
                      AND (version.status <> 'draft' OR gacha.first_published_at IS NOT NULL)
                ) THEN
                    RAISE EXCEPTION 'Published version classification is immutable';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER v2_version_exchange_only_guard
            BEFORE UPDATE ON catalog_gacha_version_prizes
            FOR EACH ROW EXECUTE FUNCTION v2_protect_version_exchange_only();
            CREATE OR REPLACE FUNCTION v2_protect_published_banner_classification()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.exchange_only IS DISTINCT FROM OLD.exchange_only AND EXISTS (
                    SELECT 1 FROM catalog_prizes prize
                    JOIN catalog_gachas gacha ON gacha.id = prize.gacha_id
                    WHERE gacha.first_published_at IS NOT NULL AND (
                        prize.external_id = OLD.external_id OR EXISTS (
                            SELECT 1 FROM content_versions version
                            JOIN content_version_assets asset ON asset.content_version_id = version.id
                            WHERE version.banner_id = OLD.id AND asset.usage_type = 'image'
                              AND (asset.presentation_asset_id = prize.presentation_asset_id OR EXISTS (
                                  SELECT 1 FROM catalog_gacha_version_prizes snapshot
                                  WHERE snapshot.prize_id = prize.id
                                    AND snapshot.presentation_asset_id = asset.presentation_asset_id
                              ))
                        )
                    )
                ) THEN
                    RAISE EXCEPTION 'Published banner classification is immutable';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER v2_published_banner_classification_guard
            BEFORE UPDATE ON content_banners
            FOR EACH ROW EXECUTE FUNCTION v2_protect_published_banner_classification();
        SQL);
    }

    public function down(): void
    {
        throw new LogicException('Exchange-only prize rights require a forward correction migration.');
    }
};
