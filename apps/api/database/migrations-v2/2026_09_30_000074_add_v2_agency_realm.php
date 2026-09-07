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
        Schema::create('agency_sessions', function (Blueprint $table): void {
            $table->char('session_id_hash', 64)->primary();
            $table->foreignId('agency_id')->constrained('agencies')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('last_activity_at');
            $table->timestampTz('idle_expires_at');
            $table->timestampTz('absolute_expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->index(['agency_id', 'revoked_at']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE agency_sessions ADD CONSTRAINT agency_sessions_values_check CHECK (
                session_id_hash ~ '^[0-9a-f]{64}$'
                AND last_activity_at >= created_at AND idle_expires_at > last_activity_at
                AND absolute_expires_at > created_at AND idle_expires_at <= absolute_expires_at
                AND idle_expires_at <= last_activity_at + INTERVAL '6 hours'
                AND absolute_expires_at <= created_at + INTERVAL '12 hours'
            )
        SQL);
        $this->auditConstraints(true);
        DB::statement('DROP TRIGGER mail_templates_fixed_set_guard ON mail_templates');
        DB::table('mail_templates')->insert([
            'public_id' => (string) Str::uuid7(),
            'template_key' => 'agency_email_changed',
            'label' => '代理店メールアドレス変更通知',
            'subject_template' => '代理店メールアドレス変更通知',
            'body_html' => '<p>{{agency_company_name}}<br>{{agency_contact_name}} 様</p>'.
                '<p>メールアドレスが変更されました。<br>変更日時：{{agency_changed_at}}</p>'.
                '<p>代理店ログイン：{{agency_login_url}}</p>',
            'revision' => 1, 'created_at' => now()->startOfSecond(), 'updated_at' => now()->startOfSecond(),
        ]);
        $this->restoreMailGuard();
    }

    public function down(): void
    {
        if (DB::table('agency_sessions')->exists()
            || DB::table('audit_logs')->where('auth_realm', 'agency')->exists()
            || DB::table('mail_deliveries')->whereIn('mail_template_id',
                DB::table('mail_templates')->select('id')->where('template_key', 'agency_email_changed'))->exists()) {
            throw new RuntimeException('Agency security history requires a forward migration.');
        }
        DB::statement('DROP TRIGGER mail_templates_fixed_set_guard ON mail_templates');
        DB::table('mail_templates')->where('template_key', 'agency_email_changed')->delete();
        $this->restoreMailGuard();
        $this->auditConstraints(false);
        Schema::dropIfExists('agency_sessions');
    }

    private function restoreMailGuard(): void
    {
        DB::statement('CREATE TRIGGER mail_templates_fixed_set_guard BEFORE INSERT OR UPDATE OR DELETE ON mail_templates FOR EACH ROW EXECUTE FUNCTION v2_mail_templates_guard_fixed_rows()');
    }

    private function auditConstraints(bool $agency): void
    {
        $values = "'system','user','admin'".($agency ? ",'agency'" : '');
        DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT audit_logs_actor_type_check');
        DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT audit_logs_auth_realm_check');
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_actor_type_check CHECK (actor_type::text IN ($values))");
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_auth_realm_check CHECK (auth_realm IS NULL OR auth_realm::text IN ($values))");
    }
};
