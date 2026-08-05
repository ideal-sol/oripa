<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    public function up(): void
    {
        Schema::table('catalog_gachas', function (Blueprint $table): void {
            $table->string('public_code', 11)->nullable();
        });

        foreach (DB::table('catalog_gachas')->orderBy('id')->pluck('id') as $id) {
            DB::table('catalog_gachas')->where('id', $id)->update([
                'public_code' => $this->uniqueCode(),
                'revision' => DB::raw('revision + 1'),
                'updated_at' => now()->startOfSecond(),
            ]);
        }
        DB::statement('SET CONSTRAINTS catalog_gachas_validate_activation IMMEDIATE');

        DB::statement('ALTER TABLE catalog_gachas ALTER COLUMN public_code SET NOT NULL');
        DB::statement(
            "ALTER TABLE catalog_gachas ADD CONSTRAINT catalog_gachas_public_code_format_check "
            ."CHECK (public_code ~ '^[A-Za-z0-9]{11}$')"
        );
        Schema::table('catalog_gachas', function (Blueprint $table): void {
            $table->unique('public_code', 'catalog_gachas_public_code_unique');
        });

        Schema::table('catalog_gacha_versions', function (Blueprint $table): void {
            $table->foreignId('category_id')->nullable()
                ->constrained('catalog_categories')->restrictOnDelete();
        });
        DB::statement(
            'ALTER TABLE catalog_gacha_versions '
            .'DISABLE TRIGGER catalog_gacha_versions_protect_draft_mutation'
        );
        DB::statement(
            'ALTER TABLE catalog_gacha_versions '
            .'DISABLE TRIGGER catalog_gacha_versions_protect_published'
        );
        DB::statement(
            'ALTER TABLE catalog_gacha_versions '
            .'DISABLE TRIGGER catalog_gacha_versions_schedule_guard'
        );
        DB::statement(<<<'SQL'
            UPDATE catalog_gacha_versions version
            SET category_id = gacha.category_id
            FROM catalog_gachas gacha
            WHERE gacha.id = version.gacha_id
        SQL);
        DB::statement(
            'ALTER TABLE catalog_gacha_versions '
            .'ENABLE TRIGGER catalog_gacha_versions_protect_draft_mutation'
        );
        DB::statement(
            'ALTER TABLE catalog_gacha_versions '
            .'ENABLE TRIGGER catalog_gacha_versions_protect_published'
        );
        DB::statement(
            'ALTER TABLE catalog_gacha_versions '
            .'ENABLE TRIGGER catalog_gacha_versions_schedule_guard'
        );

        Schema::create('catalog_gacha_version_tags', function (Blueprint $table): void {
            $table->foreignId('gacha_version_id')
                ->constrained('catalog_gacha_versions')->restrictOnDelete();
            $table->foreignId('tag_id')
                ->constrained('catalog_tags')->restrictOnDelete();
            $table->primary(['gacha_version_id', 'tag_id']);
        });
        DB::statement(<<<'SQL'
            INSERT INTO catalog_gacha_version_tags (gacha_version_id, tag_id)
            SELECT version.id, relation.tag_id
            FROM catalog_gacha_versions version
            JOIN catalog_gacha_tags relation ON relation.gacha_id = version.gacha_id
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_catalog_guard_gacha_version_tag()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE parent_id bigint;
            DECLARE parent_status text;
            DECLARE parent_archived_at timestamptz;
            BEGIN
                parent_id := CASE WHEN TG_OP = 'DELETE'
                    THEN OLD.gacha_version_id ELSE NEW.gacha_version_id END;
                SELECT status, archived_at INTO parent_status, parent_archived_at
                FROM catalog_gacha_versions WHERE id = parent_id;
                IF parent_status IS DISTINCT FROM 'draft'::text
                   OR parent_archived_at IS NOT NULL THEN
                    RAISE EXCEPTION 'Only active Draft Gacha Versions accept tag changes';
                END IF;
                IF TG_OP = 'UPDATE'
                   AND NEW.gacha_version_id IS DISTINCT FROM OLD.gacha_version_id THEN
                    RAISE EXCEPTION 'Gacha Version tag parent is immutable';
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER catalog_gacha_version_tags_guard '
            .'BEFORE INSERT OR UPDATE OR DELETE ON catalog_gacha_version_tags '
            .'FOR EACH ROW EXECUTE FUNCTION v2_catalog_guard_gacha_version_tag()'
        );
    }

    public function down(): void
    {
        DB::statement(
            'DROP TRIGGER IF EXISTS catalog_gacha_version_tags_guard '
            .'ON catalog_gacha_version_tags'
        );
        DB::statement('DROP FUNCTION IF EXISTS v2_catalog_guard_gacha_version_tag()');
        Schema::dropIfExists('catalog_gacha_version_tags');
        Schema::table('catalog_gacha_versions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('category_id');
        });
        Schema::table('catalog_gachas', function (Blueprint $table): void {
            $table->dropUnique('catalog_gachas_public_code_unique');
        });
        DB::statement(
            'ALTER TABLE catalog_gachas '
            .'DROP CONSTRAINT IF EXISTS catalog_gachas_public_code_format_check'
        );
        Schema::table('catalog_gachas', function (Blueprint $table): void {
            $table->dropColumn('public_code');
        });
    }

    private function uniqueCode(): string
    {
        do {
            $value = '';
            for ($index = 0; $index < 11; $index++) {
                $value .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (DB::table('catalog_gachas')->where('public_code', $value)->exists());

        return $value;
    }
};
