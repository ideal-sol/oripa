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
        Schema::table('sms_verification_challenges', function (Blueprint $table): void {
            $table->string('delivery_state', 16)->nullable();
            $table->string('provider_identifier', 32)->nullable();
            $table->string('provider_request_id', 191)->nullable();
            $table->string('delivery_error_category', 64)->nullable();
            $table->timestampTz('delivery_attempted_at')->nullable();
            $table->timestampTz('delivery_accepted_at')->nullable();
            $table->timestampTz('delivery_failed_at')->nullable();
        });

        DB::statement(<<<'SQL'
            UPDATE sms_verification_challenges
            SET delivery_state = 'failed',
                provider_identifier = 'sms_fours',
                delivery_error_category = 'legacy_delivery_unconfirmed',
                delivery_attempted_at = sent_at,
                delivery_failed_at = COALESCE(revoked_at, used_at, sent_at),
                revoked_at = COALESCE(revoked_at, used_at, CURRENT_TIMESTAMP)
        SQL);
        DB::statement(
            "ALTER TABLE sms_verification_challenges ALTER COLUMN delivery_state SET DEFAULT 'pending'"
        );
        DB::statement(
            'ALTER TABLE sms_verification_challenges ALTER COLUMN delivery_state SET NOT NULL'
        );
        DB::statement(
            "ALTER TABLE sms_verification_challenges ALTER COLUMN provider_identifier SET DEFAULT 'sms_fours'"
        );
        DB::statement(
            'ALTER TABLE sms_verification_challenges ALTER COLUMN provider_identifier SET NOT NULL'
        );
        DB::statement(<<<'SQL'
            ALTER TABLE sms_verification_challenges
            ADD CONSTRAINT sms_verification_challenges_delivery_check CHECK (
                delivery_state::text = ANY (ARRAY[
                    'pending'::text,
                    'sending'::text,
                    'accepted'::text,
                    'failed'::text,
                    'unknown'::text
                ])
                AND provider_identifier ~ '^[a-z][a-z0-9_]{0,31}$'
                AND (provider_request_id IS NULL OR length(btrim(provider_request_id)) > 0)
                AND (
                    delivery_error_category IS NULL
                    OR delivery_error_category ~ '^[a-z][a-z0-9_]{0,63}$'
                )
                AND (
                    (delivery_state = 'pending'
                        AND delivery_attempted_at IS NULL
                        AND delivery_accepted_at IS NULL
                        AND delivery_failed_at IS NULL
                        AND provider_request_id IS NULL
                        AND delivery_error_category IS NULL)
                    OR (delivery_state = 'sending'
                        AND delivery_attempted_at IS NOT NULL
                        AND delivery_accepted_at IS NULL
                        AND delivery_failed_at IS NULL
                        AND provider_request_id IS NULL
                        AND delivery_error_category IS NULL)
                    OR (delivery_state = 'accepted'
                        AND delivery_attempted_at IS NOT NULL
                        AND delivery_accepted_at IS NOT NULL
                        AND delivery_failed_at IS NULL
                        AND provider_request_id IS NOT NULL
                        AND delivery_error_category IS NULL)
                    OR (delivery_state IN ('failed', 'unknown')
                        AND delivery_attempted_at IS NOT NULL
                        AND delivery_accepted_at IS NULL
                        AND delivery_failed_at IS NOT NULL
                        AND delivery_error_category IS NOT NULL)
                )
            )
        SQL);
        DB::statement(
            'CREATE INDEX sms_verification_challenges_delivery_state_index '.
            'ON sms_verification_challenges (delivery_state, id)'
        );

        $this->replaceMailTemplateGuard(false);
        $now = now()->startOfSecond();
        DB::table('mail_templates')->insert([
            'public_id' => (string) Str::uuid7(),
            'template_key' => 'phone_changed',
            'label' => '電話番号変更完了時',
            'subject_template' => '電話番号変更完了のお知らせ',
            'body_html' => '<p>電話番号の変更が完了しました。</p><p>この変更に心当たりがない場合は、サポートまでお問い合わせください。</p>',
            'revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->replaceMailTemplateGuard(true);
    }

    public function down(): void
    {
        if (DB::table('mail_deliveries as delivery')
            ->join('mail_templates as template', 'template.id', '=', 'delivery.mail_template_id')
            ->where('template.template_key', 'phone_changed')
            ->exists()) {
            throw new \RuntimeException('Phone Changed Mail delivery history prevents rollback.');
        }

        $this->replaceMailTemplateGuard(false);
        DB::table('mail_templates')->where('template_key', 'phone_changed')->delete();
        $this->replaceMailTemplateGuard(true);

        DB::statement(
            'DROP INDEX IF EXISTS sms_verification_challenges_delivery_state_index'
        );
        DB::statement(
            'ALTER TABLE sms_verification_challenges '.
            'DROP CONSTRAINT IF EXISTS sms_verification_challenges_delivery_check'
        );
        Schema::table('sms_verification_challenges', function (Blueprint $table): void {
            $table->dropColumn([
                'delivery_state',
                'provider_identifier',
                'provider_request_id',
                'delivery_error_category',
                'delivery_attempted_at',
                'delivery_accepted_at',
                'delivery_failed_at',
            ]);
        });
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
};
