<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A later financial stop must not collide with a legitimate newer account.
        Schema::table('users', fn (Blueprint $table) => $table->timestampTz('closure_email_released_at')->nullable());
        DB::statement('DROP INDEX users_verified_email_unique');
        DB::statement("CREATE UNIQUE INDEX users_verified_email_unique ON users (email_normalized) WHERE email_verified_at IS NOT NULL AND state <> 'closed' AND closure_email_released_at IS NULL");
        Schema::table('external_identity_accounts', function (Blueprint $table): void {
            $table->timestampTz('superseded_at')->nullable();
            $table->dropUnique(['provider', 'issuer', 'subject_hash']);
        });
        DB::statement('CREATE UNIQUE INDEX external_identity_current_subject_unique ON external_identity_accounts (provider, issuer, subject_hash) WHERE superseded_at IS NULL');
        Schema::table('line_friendships', function (Blueprint $table): void {
            $table->timestampTz('superseded_at')->nullable();
            $table->dropUnique(['subject_hash']);
        });
        DB::statement('CREATE UNIQUE INDEX line_friendship_current_subject_unique ON line_friendships (subject_hash) WHERE superseded_at IS NULL');
        Schema::create('account_closures', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->timestampTz('completed_at');
            $table->string('closed_by', 16);
            $table->string('closure_kind', 32);
            $table->timestampTz('financial_suspended_at')->nullable();
            $table->unsignedBigInteger('forfeited_points');
            $table->unsignedBigInteger('forfeited_prizes');
            $table->uuid('request_id');
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::statement("ALTER TABLE account_closures ADD CONSTRAINT account_closure_values CHECK (closed_by::text IN ('self','admin') AND closure_kind::text IN ('normal','forced','financial') AND forfeited_points >= 0 AND forfeited_prizes >= 0 AND (closure_kind::text <> 'financial' OR financial_suspended_at IS NOT NULL))");
        Schema::create('account_closure_codes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->char('code_hash', 64);
            $table->timestampTz('sent_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('invalidated_at')->nullable();
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->index(['user_id', 'sent_at']);
        });
        DB::statement("ALTER TABLE account_closure_codes ADD CONSTRAINT account_closure_code_values CHECK (code_hash ~ '^[a-f0-9]{64}$' AND failed_attempts BETWEEN 0 AND 5 AND expires_at = sent_at + INTERVAL '10 minutes')");
        Schema::create('account_closure_phone_bans', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->char('phone_hmac', 64);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['user_id', 'phone_hmac']);
            $table->index('phone_hmac');
        });
        Schema::create('account_closure_late_payments', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('payment_id')->unique()->constrained('payments')->restrictOnDelete();
            $table->foreignId('provider_event_id')->constrained('payment_provider_events')->restrictOnDelete();
            $table->timestampTz('received_at');
            $table->boolean('same_jst_day');
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_account_closure_history_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP <> 'UPDATE' THEN RAISE EXCEPTION 'Account closure history is retained'; END IF;
                IF OLD.closure_kind <> 'normal' OR NEW.closure_kind <> 'financial'
                   OR NEW.financial_suspended_at IS NULL
                   OR (to_jsonb(OLD) - 'closure_kind' - 'financial_suspended_at') IS DISTINCT FROM
                      (to_jsonb(NEW) - 'closure_kind' - 'financial_suspended_at') THEN
                    RAISE EXCEPTION 'Account closure history is immutable';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER account_closure_history_guard BEFORE UPDATE OR DELETE ON account_closures
                FOR EACH ROW EXECUTE FUNCTION v2_account_closure_history_guard();
            CREATE TRIGGER account_closure_truncate_guard BEFORE TRUNCATE ON account_closures
                FOR EACH STATEMENT EXECUTE FUNCTION v2_account_closure_history_guard();
            SQL);
        foreach (['account_closure_phone_bans', 'account_closure_late_payments'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION v2_reject_payment_immutable_mutation()");
            DB::statement("CREATE TRIGGER {$table}_truncate_guard BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION v2_reject_payment_immutable_mutation()");
        }
        // Extend the existing predicates without dropping any financial/snapshot constraint.
        $this->extendCheck('point_ledger_entries', 'point_ledger_type_entry_check', "'reverse'::text", "'reverse'::text, 'forfeit'::text");
        $this->extendCheck('user_prizes', 'user_prize_status_check', "'expired'::text", "'expired'::text, 'forfeited'::text");
        DB::statement('DROP TRIGGER mail_templates_fixed_set_guard ON mail_templates');
        foreach ([
            'account_closure_code' => ['退会認証コード', '【オリポケ】退会の認証コード', '<p>退会の認証コードは {{closure_code}} です。有効期限は10分です。</p><p>心当たりがない場合は、このメールを破棄してください。</p>'],
            'account_closure_payment' => ['退会当日の後着決済確認', '【オリポケ】退会後に確認された決済について', '<p>決済が後から確認されました。退会後のためコインは付与していません。返金対応を運営が確認します。</p><p>退会後も決済関連のお知らせメールが送られる場合があります。</p>'],
        ] as $key => [$label, $subject, $body]) {
            DB::table('mail_templates')->insert([
                'public_id' => (string) \Illuminate\Support\Str::uuid7(), 'template_key' => $key,
                'label' => $label, 'subject_template' => $subject, 'body_html' => $body, 'revision' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::statement('CREATE TRIGGER mail_templates_fixed_set_guard BEFORE INSERT OR UPDATE OR DELETE ON mail_templates FOR EACH ROW EXECUTE FUNCTION v2_mail_templates_guard_fixed_rows()');
    }

    private function extendCheck(string $table, string $name, string $from, string $to): void
    {
        $definition = DB::selectOne('SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid = ?::regclass AND conname = ?', [$table, $name])->definition;
        if (! str_contains($definition, $from)) {
            throw new RuntimeException('Account closure constraint source does not match.');
        }
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$name}");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} ".str_replace($from, $to, $definition));
    }

    public function down(): void
    {
        throw new LogicException('Account closure history is retained; use a forward correction.');
    }
};
