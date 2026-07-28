<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_banners', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->string('code', 64)->unique();
            $table->string('status', 16)->default('draft');
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'id']);
        });
        Schema::create('content_notices', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->string('slug', 128)->unique();
            $table->string('status', 16)->default('draft');
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'id']);
        });
        Schema::create('content_static_pages', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->string('slug', 128)->unique();
            $table->boolean('is_legal')->default(false);
            $table->string('status', 16)->default('draft');
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'is_legal', 'id']);
        });

        Schema::create('content_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('banner_id')->nullable()
                ->constrained('content_banners')->restrictOnDelete();
            $table->foreignId('notice_id')->nullable()
                ->constrained('content_notices')->restrictOnDelete();
            $table->foreignId('static_page_id')->nullable()
                ->constrained('content_static_pages')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status', 16)->default('draft');
            $table->string('title', 191);
            $table->text('summary')->nullable();
            $table->text('body_html')->nullable();
            $table->string('link_url', 2048)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_important')->default(false);
            $table->timestampTz('publish_start_at');
            $table->timestampTz('publish_end_at')->nullable();
            $table->char('content_checksum', 64);
            $table->foreignId('created_by_admin_id')
                ->constrained('admins')->restrictOnDelete();
            $table->foreignId('published_by_admin_id')->nullable()
                ->constrained('admins')->restrictOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->unique(['banner_id', 'version_number']);
            $table->unique(['notice_id', 'version_number']);
            $table->unique(['static_page_id', 'version_number']);
            $table->index(['status', 'publish_start_at', 'publish_end_at', 'id']);
        });

        foreach ([
            ['content_banners', 'content_banners_published_version_id_foreign'],
            ['content_notices', 'content_notices_published_version_id_foreign'],
            ['content_static_pages', 'content_static_pages_published_version_id_foreign'],
        ] as [$table, $name]) {
            Schema::table($table, function (Blueprint $blueprint) use ($name): void {
                $blueprint->foreign('published_version_id', $name)
                    ->references('id')->on('content_versions')->restrictOnDelete();
            });
        }

        Schema::create('content_version_assets', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('content_version_id')
                ->constrained('content_versions')->restrictOnDelete();
            $table->foreignId('presentation_asset_id')
                ->constrained('catalog_presentation_assets')->restrictOnDelete();
            $table->string('usage_type', 16);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->unique(
                ['content_version_id', 'usage_type'],
                'content_version_asset_usage_unique'
            );
        });

        Schema::create('contact_inquiries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->string('receipt_code', 32)->unique();
            $table->foreignId('user_id')->nullable()
                ->constrained('users')->restrictOnDelete();
            $table->text('name_ciphertext');
            $table->text('email_ciphertext');
            $table->text('phone_ciphertext')->nullable();
            $table->text('subject_ciphertext');
            $table->text('body_ciphertext');
            $table->char('email_correlation_hash', 64);
            $table->string('status', 16)->default('new');
            $table->foreignId('assigned_admin_id')->nullable()
                ->constrained('admins')->restrictOnDelete();
            $table->timestampTz('received_at');
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('retention_until');
            $table->timestampsTz();
            $table->index(['status', 'received_at', 'id']);
            $table->index(['user_id', 'received_at', 'id']);
            $table->index(['email_correlation_hash', 'received_at', 'id']);
        });

        Schema::create('contact_status_histories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('contact_inquiry_id')
                ->constrained('contact_inquiries')->restrictOnDelete();
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->foreignId('actor_admin_id')->nullable()
                ->constrained('admins')->restrictOnDelete();
            $table->string('reason_code', 64);
            $table->uuid('request_id');
            $table->timestampTz('occurred_at');
            $table->timestampsTz();
            $table->index(['contact_inquiry_id', 'id']);
        });
        Schema::create('contact_internal_notes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('contact_inquiry_id')
                ->constrained('contact_inquiries')->restrictOnDelete();
            $table->foreignId('admin_id')
                ->constrained('admins')->restrictOnDelete();
            $table->text('note_ciphertext');
            $table->uuid('request_id');
            $table->timestampTz('created_at');
            $table->index(['contact_inquiry_id', 'id']);
        });
        Schema::create('contact_reply_requests', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('contact_inquiry_id')
                ->constrained('contact_inquiries')->restrictOnDelete();
            $table->foreignId('requested_by_admin_id')
                ->constrained('admins')->restrictOnDelete();
            $table->text('message_ciphertext');
            $table->uuid('request_id');
            $table->timestampTz('created_at');
            $table->index(['contact_inquiry_id', 'id']);
        });

        $this->constraints();
        $this->guards();
    }

    public function down(): void
    {
        foreach ([
            'content_banners',
            'content_notices',
            'content_static_pages',
        ] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropForeign(['published_version_id']);
            });
        }
        foreach ([
            'contact_reply_requests',
            'contact_internal_notes',
            'contact_status_histories',
            'contact_inquiries',
            'content_version_assets',
            'content_versions',
            'content_static_pages',
            'content_notices',
            'content_banners',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP FUNCTION IF EXISTS v2_content_protect_published_version() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS v2_content_protect_published_asset() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS v2_content_validate_published_pointer() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS v2_contact_reject_history_mutation() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS v2_contact_reject_delete() CASCADE');
    }

    private function constraints(): void
    {
        foreach (['content_banners', 'content_notices', 'content_static_pages'] as $table) {
            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT {$table}_status_check CHECK (".
                "status::text = ANY (ARRAY['draft'::text,'published'::text,'archived'::text]) ".
                "AND ((status = 'published' AND published_version_id IS NOT NULL) ".
                "OR status <> 'published'))"
            );
        }
        DB::statement(<<<'SQL'
            ALTER TABLE content_versions
            ADD CONSTRAINT content_versions_values_check CHECK (
                version_number > 0
                AND status::text = ANY (ARRAY['draft'::text,'published'::text])
                AND num_nonnulls(banner_id, notice_id, static_page_id) = 1
                AND (publish_end_at IS NULL OR publish_end_at > publish_start_at)
                AND content_checksum ~ '^[0-9a-f]{64}$'
                AND (
                    (status = 'draft' AND published_by_admin_id IS NULL AND published_at IS NULL)
                    OR
                    (status = 'published' AND published_by_admin_id IS NOT NULL
                        AND published_at IS NOT NULL)
                )
                AND (
                    (banner_id IS NOT NULL AND body_html IS NULL AND is_important = false)
                    OR
                    (notice_id IS NOT NULL AND body_html IS NOT NULL)
                    OR
                    (static_page_id IS NOT NULL AND body_html IS NOT NULL
                        AND link_url IS NULL AND is_important = false)
                )
            )
            SQL);
        DB::statement(
            "ALTER TABLE content_version_assets ADD CONSTRAINT content_asset_usage_check ".
            "CHECK (usage_type::text = ANY (ARRAY['image'::text,'thumbnail'::text]))"
        );
        DB::statement(<<<'SQL'
            ALTER TABLE contact_inquiries
            ADD CONSTRAINT contact_inquiries_values_check CHECK (
                email_correlation_hash ~ '^[0-9a-f]{64}$'
                AND status::text = ANY (
                    ARRAY['new'::text,'in_progress'::text,'replied'::text,'closed'::text]
                )
                AND retention_until > received_at
                AND (
                    (status = 'closed' AND closed_at IS NOT NULL)
                    OR
                    (status <> 'closed' AND closed_at IS NULL)
                )
            )
            SQL);
        DB::statement(
            "ALTER TABLE contact_status_histories ADD CONSTRAINT contact_history_status_check ".
            "CHECK ((from_status IS NULL OR from_status::text = ANY (".
            "ARRAY['new'::text,'in_progress'::text,'replied'::text,'closed'::text])) ".
            "AND to_status::text = ANY (".
            "ARRAY['new'::text,'in_progress'::text,'replied'::text,'closed'::text]) ".
            "AND reason_code ~ '^[a-z][a-z0-9_.:-]{0,63}$')"
        );
    }

    private function guards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_content_protect_published_version()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' OR OLD.status = 'published' THEN
                    RAISE EXCEPTION 'Published Content Version is immutable';
                END IF;
                IF NEW.version_number <> OLD.version_number
                    OR NEW.banner_id IS DISTINCT FROM OLD.banner_id
                    OR NEW.notice_id IS DISTINCT FROM OLD.notice_id
                    OR NEW.static_page_id IS DISTINCT FROM OLD.static_page_id
                THEN
                    RAISE EXCEPTION 'Content Version identity is immutable';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER content_versions_protect_published '.
            'BEFORE UPDATE OR DELETE ON content_versions FOR EACH ROW '.
            'EXECUTE FUNCTION v2_content_protect_published_version()'
        );
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_content_protect_published_asset()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE version_id bigint;
            BEGIN
                version_id := CASE
                    WHEN TG_OP = 'DELETE' THEN OLD.content_version_id
                    ELSE NEW.content_version_id
                END;
                IF EXISTS (
                    SELECT 1 FROM content_versions
                    WHERE id = version_id AND status = 'published'
                ) THEN
                    RAISE EXCEPTION 'Published Content Version Asset is immutable';
                END IF;
                IF TG_OP = 'UPDATE'
                    AND OLD.content_version_id IS DISTINCT FROM NEW.content_version_id
                    AND EXISTS (
                        SELECT 1 FROM content_versions
                        WHERE id = OLD.content_version_id AND status = 'published'
                    )
                THEN
                    RAISE EXCEPTION 'Published Content Version Asset is immutable';
                END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER content_version_assets_protect_published '.
            'BEFORE INSERT OR UPDATE OR DELETE ON content_version_assets FOR EACH ROW '.
            'EXECUTE FUNCTION v2_content_protect_published_asset()'
        );
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_content_validate_published_pointer()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE valid_pointer boolean;
            BEGIN
                IF NEW.published_version_id IS NULL THEN
                    RETURN NEW;
                END IF;
                EXECUTE format(
                    'SELECT EXISTS (SELECT 1 FROM content_versions WHERE id = $1 ' ||
                    'AND %I = $2 AND status = ''published'')',
                    TG_ARGV[0]
                ) INTO valid_pointer USING NEW.published_version_id, NEW.id;
                IF NOT valid_pointer THEN
                    RAISE EXCEPTION 'Published Content pointer is invalid';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        foreach ([
            ['content_banners', 'banner_id'],
            ['content_notices', 'notice_id'],
            ['content_static_pages', 'static_page_id'],
        ] as [$table, $column]) {
            DB::statement(
                "CREATE TRIGGER {$table}_validate_published_pointer ".
                "BEFORE INSERT OR UPDATE OF published_version_id ON {$table} FOR EACH ROW ".
                "EXECUTE FUNCTION v2_content_validate_published_pointer('{$column}')"
            );
        }
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_contact_reject_history_mutation()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Contact Status History is append-only';
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER contact_status_histories_reject_mutation '.
            'BEFORE UPDATE OR DELETE ON contact_status_histories FOR EACH ROW '.
            'EXECUTE FUNCTION v2_contact_reject_history_mutation()'
        );
        DB::statement(
            'CREATE TRIGGER contact_status_histories_reject_truncate '.
            'BEFORE TRUNCATE ON contact_status_histories FOR EACH STATEMENT '.
            'EXECUTE FUNCTION v2_contact_reject_history_mutation()'
        );
        foreach (['contact_internal_notes', 'contact_reply_requests'] as $table) {
            DB::statement(
                "CREATE TRIGGER {$table}_reject_mutation BEFORE UPDATE OR DELETE ON {$table} ".
                'FOR EACH ROW EXECUTE FUNCTION v2_contact_reject_history_mutation()'
            );
            DB::statement(
                "CREATE TRIGGER {$table}_reject_truncate BEFORE TRUNCATE ON {$table} ".
                'FOR EACH STATEMENT EXECUTE FUNCTION v2_contact_reject_history_mutation()'
            );
        }
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_contact_reject_delete()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Contact Inquiry retention forbids physical deletion';
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER contact_inquiries_reject_delete '.
            'BEFORE DELETE ON contact_inquiries FOR EACH ROW '.
            'EXECUTE FUNCTION v2_contact_reject_delete()'
        );
    }
};
