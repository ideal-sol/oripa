<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_templates', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->string('template_key', 64)->unique();
            $table->string('label', 100);
            $table->string('subject_template', 191);
            $table->text('body_html');
            $table->unsignedInteger('revision')->default(1);
            $table->timestampsTz();
        });

        Schema::create('mail_deliveries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('mail_template_id')
                ->constrained('mail_templates')->restrictOnDelete();
            $table->string('event_key', 255)->unique();
            $table->string('source_type', 32);
            $table->uuid('source_public_id');
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('failure_code', 64)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'id']);
            $table->index(['source_type', 'source_public_id']);
        });

        DB::statement(
            "ALTER TABLE mail_templates ADD CONSTRAINT mail_templates_values_check CHECK (".
            "template_key ~ '^[a-z][a-z0-9_]{0,63}$' AND length(btrim(label)) > 0 ".
            "AND length(btrim(subject_template)) > 0 AND length(btrim(body_html)) > 0 ".
            'AND revision > 0)'
        );
        DB::statement(
            "ALTER TABLE mail_deliveries ADD CONSTRAINT mail_deliveries_values_check CHECK (".
            "source_type::text = ANY (ARRAY['user'::text,'payment'::text,".
            "'shipping_request'::text,'contact_inquiry'::text]) AND ".
            "status::text = ANY (ARRAY['pending'::text,'sending'::text,".
            "'sent'::text,'failed'::text]) AND attempts <= 1 AND ".
            "((status = 'pending' AND attempts = 0 AND sent_at IS NULL AND failure_code IS NULL) OR ".
            "(status = 'sending' AND attempts = 1 AND sent_at IS NULL AND failure_code IS NULL) OR ".
            "(status = 'sent' AND attempts = 1 AND sent_at IS NOT NULL AND failure_code IS NULL) OR ".
            "(status = 'failed' AND attempts = 1 AND sent_at IS NULL AND failure_code IS NOT NULL)))"
        );

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

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_mail_templates_guard_fixed_rows()
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

    public function down(): void
    {
        Schema::dropIfExists('mail_deliveries');
        DB::statement('DROP TRIGGER IF EXISTS mail_templates_fixed_set_guard ON mail_templates');
        DB::statement('DROP FUNCTION IF EXISTS v2_mail_templates_guard_fixed_rows()');
        Schema::dropIfExists('mail_templates');
    }

    /** @return array<string, array{label: string, subject: string, body_html: string}> */
    private function templates(): array
    {
        return [
            'email_verification' => [
                'label' => '認証リンク送信時',
                'subject' => 'メールアドレス認証のお願い',
                'body_html' => '<p>{{user_name}} 様</p><p>ご登録ありがとうございます。</p><p>以下のリンクからメールアドレスの認証をお願いいたします。</p><p>{{verification_url}}</p>',
            ],
            'registration_completed' => [
                'label' => '認証成功登録時',
                'subject' => '会員登録が完了しました',
                'body_html' => '<p>{{user_name}} 様</p><p>メールアドレスの認証が完了しました。</p><p>会員登録が完了しましたので、サービスをご利用いただけます。</p>',
            ],
            'coin_purchase_completed' => [
                'label' => 'コイン購入完了時',
                'subject' => 'コインの購入が完了しました',
                'body_html' => '<p>{{user_name}} 様</p><p>コインの購入が完了しました。</p><p>購入プラン：{{purchase_plan}}<br>購入金額：{{purchase_amount}}</p><p>ご購入ありがとうございました。</p>',
            ],
            'shipping_requested' => [
                'label' => '発送依頼時',
                'subject' => '発送依頼を受け付けました',
                'body_html' => '<p>{{user_name}} 様</p><p>発送依頼を受け付けました。</p><p>ガチャ名：{{gacha_names}}<br>景品名：{{prize_names}}<br>お届け先：{{address}}</p><p>発送までしばらくお待ちください。</p>',
            ],
            'shipping_completed' => [
                'label' => '発送完了時',
                'subject' => '景品の発送が完了しました',
                'body_html' => '<p>{{user_name}} 様</p><p>景品の発送が完了しました。</p><p>ガチャ名：{{gacha_names}}<br>景品名：{{prize_names}}</p><p>商品到着までしばらくお待ちください。</p>',
            ],
            'user_closed' => [
                'label' => '退会時',
                'subject' => '退会手続きが完了しました',
                'body_html' => '<p>{{user_name}} 様</p><p>退会手続きが完了しました。</p><p>これまでサービスをご利用いただき、ありがとうございました。</p>',
            ],
            'contact_received' => [
                'label' => 'お問い合わせ完了時',
                'subject' => 'お問い合わせを受け付けました',
                'body_html' => '<p>{{user_name}} 様</p><p>お問い合わせを受け付けました。</p><p>お問い合わせ内容：<br>{{contact_body}}</p><p>内容を確認のうえ、必要に応じてご連絡いたします。</p>',
            ],
        ];
    }
};
