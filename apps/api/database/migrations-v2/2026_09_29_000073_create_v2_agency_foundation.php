<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        Schema::create('agencies', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->string('company_name', 200);
            $table->string('contact_name', 200);
            $table->string('phone', 40);
            $table->string('email', 320);
            $table->string('normalized_email', 320)->unique();
            $table->string('address', 1000);
            $table->text('memo')->nullable();
            $table->string('login_id', 6)->unique();
            $table->string('password_hash', 255);
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('revision')->default(1);
            $table->timestampsTz();
        });
        DB::statement(<<<'SQL'
            ALTER TABLE agencies ADD CONSTRAINT agencies_values_check CHECK (
                login_id COLLATE "C" ~ '^[0-9]{6}$'
                AND normalized_email = lower(btrim(email))
                AND length(btrim(company_name)) > 0 AND length(btrim(contact_name)) > 0
                AND length(btrim(phone)) > 0 AND length(btrim(address)) > 0
                AND password_hash LIKE '$argon2id$%'
                AND status IN ('active', 'suspended') AND revision > 0
            )
        SQL);
        Schema::create('agency_advertising_codes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('agency_id')->constrained('agencies')->restrictOnDelete()->restrictOnUpdate();
            $table->string('code', 8)->collation('C')->unique();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['agency_id', 'id']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE agency_advertising_codes ADD CONSTRAINT agency_advertising_codes_format_check
            CHECK (code ~ '^[A-Za-z0-9]{8}$')
        SQL);
        Schema::create('user_advertising_attributions', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('advertising_code_id')->constrained('agency_advertising_codes')->restrictOnDelete()->restrictOnUpdate();
            $table->timestampTz('attributed_at');
        });
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_agency_guard_identity() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Agency history cannot be deleted';
                END IF;
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.public_id IS DISTINCT FROM OLD.public_id
                   OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'Agency identity is immutable';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER agencies_identity_guard BEFORE UPDATE OR DELETE ON agencies
            FOR EACH ROW EXECUTE FUNCTION v2_agency_guard_identity();
            CREATE OR REPLACE FUNCTION v2_agency_guard_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Advertising history is append only';
            END;
            $$;
            CREATE TRIGGER agency_advertising_codes_immutable BEFORE UPDATE OR DELETE ON agency_advertising_codes
            FOR EACH ROW EXECUTE FUNCTION v2_agency_guard_append_only();
            CREATE TRIGGER user_advertising_attributions_immutable BEFORE UPDATE OR DELETE ON user_advertising_attributions
            FOR EACH ROW EXECUTE FUNCTION v2_agency_guard_append_only();
        SQL);
        $this->mailSourceConstraint(true);
        DB::statement('DROP TRIGGER mail_templates_fixed_set_guard ON mail_templates');
        foreach ($this->templates() as $key => $label) {
            $credential = $key !== 'agency_password_changed';
            DB::table('mail_templates')->insert([
                'public_id' => (string) Str::uuid7(),
                'template_key' => $key,
                'label' => $label,
                'subject_template' => $label,
                'body_html' => '<p>{{agency_company_name}}<br>{{agency_contact_name}} 様</p>'.
                    ($credential
                        ? '<p>Login ID：{{agency_login_id}}<br>パスワード：{{agency_password}}<br>広告コード：{{agency_advertising_code}}</p>'
                        : '<p>パスワードが変更されました。<br>変更日時：{{agency_changed_at}}</p>').
                    '<p>代理店ログイン：{{agency_login_url}}</p>',
                'revision' => 1,
                'created_at' => now()->startOfSecond(),
                'updated_at' => now()->startOfSecond(),
            ]);
        }
        $this->restoreMailGuard();
    }

    public function down(): void
    {
        if (DB::table('agencies')->exists() || DB::table('mail_deliveries')->where('source_type', 'agency')->exists()) {
            throw new RuntimeException('Agency business history requires a forward migration.');
        }
        DB::statement('DROP TRIGGER mail_templates_fixed_set_guard ON mail_templates');
        DB::table('mail_templates')->whereIn('template_key', array_keys($this->templates()))->delete();
        $this->restoreMailGuard();
        $this->mailSourceConstraint(false);
        Schema::dropIfExists('user_advertising_attributions');
        Schema::dropIfExists('agency_advertising_codes');
        Schema::dropIfExists('agencies');
        DB::statement('DROP FUNCTION v2_agency_guard_append_only()');
        DB::statement('DROP FUNCTION v2_agency_guard_identity()');
    }

    private function restoreMailGuard(): void
    {
        DB::statement('CREATE TRIGGER mail_templates_fixed_set_guard BEFORE INSERT OR UPDATE OR DELETE ON mail_templates FOR EACH ROW EXECUTE FUNCTION v2_mail_templates_guard_fixed_rows()');
    }

    private function mailSourceConstraint(bool $agency): void
    {
        DB::statement('ALTER TABLE mail_deliveries DROP CONSTRAINT mail_deliveries_values_check');
        $sources = "'user','payment','shipping_request','contact_inquiry'".($agency ? ",'agency'" : '');
        DB::statement("ALTER TABLE mail_deliveries ADD CONSTRAINT mail_deliveries_values_check CHECK (".
            "source_type IN ($sources) AND status IN ('pending','sending','sent','failed') AND attempts <= 1 AND ".
            "((status = 'pending' AND attempts = 0 AND sent_at IS NULL AND failure_code IS NULL) OR ".
            "(status = 'sending' AND attempts = 1 AND sent_at IS NULL AND failure_code IS NULL) OR ".
            "(status = 'sent' AND attempts = 1 AND sent_at IS NOT NULL AND failure_code IS NULL) OR ".
            "(status = 'failed' AND attempts = 1 AND sent_at IS NULL AND failure_code IS NOT NULL)))");
    }

    private function templates(): array
    {
        return [
            'agency_account_created' => '代理店アカウント作成通知',
            'agency_password_changed' => '代理店パスワード変更通知',
            'agency_login_information_reissued' => '代理店ログイン情報再発行通知',
        ];
    }
};
