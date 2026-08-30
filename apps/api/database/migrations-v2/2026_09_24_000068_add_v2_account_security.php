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
        DB::statement(
            'ALTER TABLE password_reset_tokens DROP CONSTRAINT password_reset_tokens_ttl_check'
        );
        DB::statement(
            'ALTER TABLE password_reset_tokens ADD CONSTRAINT password_reset_tokens_ttl_check '.
            "CHECK (expires_at <= created_at + INTERVAL '60 minutes')"
        );

        Schema::create('user_email_change_requests', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('new_email_display', 320);
            $table->string('new_email_normalized', 320);
            $table->char('token_hash', 64)->unique();
            $table->char('initiating_session_hash', 64);
            $table->string('redirect_path', 255);
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['new_email_normalized', 'used_at', 'revoked_at']);
        });
        DB::statement(
            "ALTER TABLE user_email_change_requests ADD CONSTRAINT user_email_change_requests_values_check CHECK (".
            "length(btrim(new_email_display)) > 0 AND ".
            "new_email_normalized = lower(new_email_normalized) AND ".
            "new_email_normalized = btrim(new_email_normalized) AND ".
            "token_hash ~ '^[0-9a-f]{64}$' AND ".
            "initiating_session_hash ~ '^[0-9a-f]{64}$' AND ".
            "redirect_path LIKE '/%' AND redirect_path NOT LIKE '//%' AND ".
            'failed_attempts BETWEEN 0 AND 5 AND expires_at > created_at AND '.
            "expires_at <= created_at + INTERVAL '60 minutes' AND ".
            'NOT (used_at IS NOT NULL AND revoked_at IS NOT NULL) AND '.
            '(used_at IS NULL OR used_at >= created_at) AND '.
            '(revoked_at IS NULL OR revoked_at >= created_at))'
        );
        DB::statement(
            'CREATE UNIQUE INDEX user_email_change_requests_active_user_unique '.
            'ON user_email_change_requests (user_id) '.
            'WHERE used_at IS NULL AND revoked_at IS NULL'
        );

        $this->replaceMailTemplateGuard(false);
        $now = now()->startOfSecond();
        foreach ($this->templates() as $key => $template) {
            DB::table('mail_templates')->insert([
                'public_id' => (string) Str::uuid7(),
                'template_key' => $key,
                'label' => $template['label'],
                'subject_template' => $template['subject'],
                'body_html' => $template['body_html'],
                'revision' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $this->replaceMailTemplateGuard(true);
    }

    public function down(): void
    {
        $this->replaceMailTemplateGuard(false);
        DB::table('mail_templates')->whereIn('template_key', array_keys($this->templates()))->delete();
        $this->replaceMailTemplateGuard(true);

        Schema::dropIfExists('user_email_change_requests');

        DB::statement(
            "UPDATE password_reset_tokens SET expires_at = LEAST(expires_at, created_at + INTERVAL '30 minutes')"
        );
        DB::statement(
            'ALTER TABLE password_reset_tokens DROP CONSTRAINT password_reset_tokens_ttl_check'
        );
        DB::statement(
            'ALTER TABLE password_reset_tokens ADD CONSTRAINT password_reset_tokens_ttl_check '.
            "CHECK (expires_at <= created_at + INTERVAL '30 minutes')"
        );
    }

    private function replaceMailTemplateGuard(bool $create): void
    {
        DB::statement('DROP TRIGGER IF EXISTS mail_templates_fixed_set_guard ON mail_templates');
        DB::statement('DROP FUNCTION IF EXISTS v2_mail_templates_guard_fixed_rows()');
        if (! $create) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION v2_mail_templates_guard_fixed_rows()
            RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'INSERT' OR TG_OP = 'DELETE'
                   OR NEW.template_key IS DISTINCT FROM OLD.template_key
                   OR NEW.public_id IS DISTINCT FROM OLD.public_id
                   OR NEW.label IS DISTINCT FROM OLD.label THEN
                    RAISE EXCEPTION 'V2 Mail Templates are a fixed canonical set';
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);
        DB::statement(
            'CREATE TRIGGER mail_templates_fixed_set_guard '.
            'BEFORE INSERT OR UPDATE OR DELETE ON mail_templates '.
            'FOR EACH ROW EXECUTE FUNCTION v2_mail_templates_guard_fixed_rows()'
        );
    }

    /** @return array<string, array{label: string, subject: string, body_html: string}> */
    private function templates(): array
    {
        return [
            'password_reset' => [
                'label' => 'パスワード再設定時',
                'subject' => 'パスワード再設定のご案内',
                'body_html' => '<p>パスワード再設定のリクエストを受け付けました。</p><p>以下のリンクから新しいパスワードを設定してください。</p><p>【パスワード再設定】<br>{{reset_url}}</p><p>このリンクの有効期限は{{expires_in_minutes}}分です。</p><p>このメールに心当たりがない場合は、パスワードを変更する必要はありません。<br>このメールは破棄してください。</p>',
            ],
            'email_change_verification' => [
                'label' => 'メールアドレス変更認証時',
                'subject' => 'メールアドレス変更の確認',
                'body_html' => '<p>メールアドレス変更のリクエストを受け付けました。</p><p>以下のリンクを開いて、メールアドレスの変更を完了してください。</p><p>【メールアドレス変更を完了する】<br>{{email_change_verification_url}}</p><p>このリンクの有効期限は{{expires_in_minutes}}分です。</p><p>このメールに心当たりがない場合は、リンクを開かず、<br>このメールを破棄してください。</p>',
            ],
            'email_change_completed' => [
                'label' => 'メールアドレス変更完了時',
                'subject' => 'メールアドレス変更完了のお知らせ',
                'body_html' => '<p>メールアドレスの変更が完了しました。</p><p>今後ログインする際は、変更後のメールアドレスをご利用ください。</p><p>この変更に心当たりがない場合は、サポートまでお問い合わせください。</p>',
            ],
            'password_changed' => [
                'label' => 'パスワード変更完了時',
                'subject' => 'パスワード変更完了のお知らせ',
                'body_html' => '<p>パスワードが変更されました。</p><p>この変更に心当たりがない場合は、<br>速やかにパスワード再設定を行ってください。</p>',
            ],
        ];
    }
};
