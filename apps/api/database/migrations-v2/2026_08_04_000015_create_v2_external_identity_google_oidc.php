<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('password_login_enabled')->default(true);
        });

        Schema::create('external_identity_accounts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('provider', 16);
            $table->string('issuer', 255);
            $table->char('subject_hash', 64);
            $table->timestampTz('linked_at');
            $table->timestampTz('last_authenticated_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['provider', 'issuer', 'subject_hash']);
            $table->unique(['user_id', 'provider']);
            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('external_identity_transactions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->string('provider', 16);
            $table->string('purpose', 24);
            $table->char('state_hash', 64)->unique();
            $table->char('nonce_hash', 64);
            $table->text('code_verifier_ciphertext');
            $table->char('browser_binding_hash', 64);
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->char('user_session_hash', 64)->nullable();
            $table->string('return_path', 255);
            $table->string('redirect_uri', 2048);
            $table->uuid('request_id');
            $table->string('status', 16)->default('pending');
            $table->timestampTz('expires_at');
            $table->timestampTz('processing_at')->nullable();
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index(['user_id', 'purpose', 'status']);
            $table->index(['expires_at', 'status']);
        });

        Schema::create('external_identity_account_histories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('external_identity_account_id')
                ->constrained('external_identity_accounts')
                ->restrictOnDelete();
            $table->string('action', 24);
            $table->uuid('request_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement(
            "ALTER TABLE external_identity_accounts ADD CONSTRAINT ".
            "external_identity_accounts_provider_check CHECK (provider = 'google')"
        );
        DB::statement(
            "ALTER TABLE external_identity_accounts ADD CONSTRAINT ".
            "external_identity_accounts_issuer_check ".
            "CHECK (issuer = 'https://accounts.google.com')"
        );
        DB::statement(
            "ALTER TABLE external_identity_accounts ADD CONSTRAINT ".
            "external_identity_accounts_subject_hash_check ".
            "CHECK (subject_hash ~ '^[0-9a-f]{64}$')"
        );
        DB::statement(
            'ALTER TABLE external_identity_accounts ADD CONSTRAINT '.
            'external_identity_accounts_time_check CHECK ('.
            'last_authenticated_at IS NULL OR last_authenticated_at >= linked_at)'
        );

        foreach (
            ['state_hash', 'nonce_hash', 'browser_binding_hash', 'user_session_hash'] as $column
        ) {
            DB::statement(
                "ALTER TABLE external_identity_transactions ADD CONSTRAINT ".
                "external_identity_transactions_{$column}_check CHECK (".
                ($column === 'user_session_hash'
                    ? "{$column} IS NULL OR {$column} ~ '^[0-9a-f]{64}$'"
                    : "{$column} ~ '^[0-9a-f]{64}$'").
                ')'
            );
        }
        DB::statement(
            "ALTER TABLE external_identity_transactions ADD CONSTRAINT ".
            "external_identity_transactions_provider_check CHECK (provider = 'google')"
        );
        DB::statement(
            "ALTER TABLE external_identity_transactions ADD CONSTRAINT ".
            "external_identity_transactions_purpose_check ".
            "CHECK (purpose::text = ANY (ARRAY[".
            "'login'::text, 'link'::text, 'reauthentication'::text]))"
        );
        DB::statement(
            "ALTER TABLE external_identity_transactions ADD CONSTRAINT ".
            "external_identity_transactions_status_check ".
            "CHECK (status::text = ANY (ARRAY[".
            "'pending'::text, 'processing'::text, 'completed'::text, ".
            "'failed'::text, 'expired'::text, 'revoked'::text]))"
        );
        DB::statement(
            'ALTER TABLE external_identity_transactions ADD CONSTRAINT '.
            'external_identity_transactions_ttl_check CHECK ('.
            "expires_at > created_at AND expires_at <= created_at + INTERVAL '10 minutes')"
        );
        DB::statement(
            'ALTER TABLE external_identity_transactions ADD CONSTRAINT '.
            'external_identity_transactions_binding_check CHECK ('.
            "(purpose = 'login' AND user_id IS NULL AND user_session_hash IS NULL) OR ".
            "(purpose::text = ANY (ARRAY['link'::text, 'reauthentication'::text]) ".
            'AND user_id IS NOT NULL '.
            'AND user_session_hash IS NOT NULL))'
        );
        DB::statement(
            'ALTER TABLE external_identity_transactions ADD CONSTRAINT '.
            'external_identity_transactions_terminal_time_check CHECK ('.
            "(status = 'pending' AND processing_at IS NULL AND used_at IS NULL) OR ".
            "(status = 'processing' AND processing_at IS NOT NULL AND used_at IS NULL) OR ".
            "(status = 'completed' AND processing_at IS NOT NULL AND used_at IS NOT NULL) OR ".
            "(status::text = ANY (ARRAY[".
            "'failed'::text, 'expired'::text, 'revoked'::text])))"
        );
        DB::statement(
            "ALTER TABLE external_identity_account_histories ADD CONSTRAINT ".
            "external_identity_account_histories_action_check ".
            "CHECK (action::text = ANY (ARRAY[".
            "'linked'::text, 'authenticated'::text, 'unlinked'::text]))"
        );

        $this->installMutationGuards();
    }

    public function down(): void
    {
        DB::statement(
            'DROP FUNCTION IF EXISTS v2_external_identity_reject_history_mutation() CASCADE'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS v2_external_identity_protect_account() CASCADE'
        );
        DB::statement(
            'DROP FUNCTION IF EXISTS v2_external_identity_guard_transaction() CASCADE'
        );
        Schema::dropIfExists('external_identity_account_histories');
        Schema::dropIfExists('external_identity_transactions');
        Schema::dropIfExists('external_identity_accounts');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('password_login_enabled');
        });
    }

    private function installMutationGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_external_identity_guard_transaction()
            RETURNS trigger AS $$
            BEGIN
                IF TG_OP IN ('DELETE', 'TRUNCATE') THEN
                    RAISE EXCEPTION 'External identity transaction deletion is forbidden';
                END IF;
                IF NEW.public_id <> OLD.public_id
                    OR NEW.provider <> OLD.provider
                    OR NEW.purpose <> OLD.purpose
                    OR NEW.state_hash <> OLD.state_hash
                    OR NEW.nonce_hash <> OLD.nonce_hash
                    OR NEW.code_verifier_ciphertext <> OLD.code_verifier_ciphertext
                    OR NEW.browser_binding_hash <> OLD.browser_binding_hash
                    OR NEW.user_id IS DISTINCT FROM OLD.user_id
                    OR NEW.user_session_hash IS DISTINCT FROM OLD.user_session_hash
                    OR NEW.return_path <> OLD.return_path
                    OR NEW.redirect_uri <> OLD.redirect_uri
                    OR NEW.request_id <> OLD.request_id
                    OR NEW.expires_at <> OLD.expires_at
                    OR NEW.created_at <> OLD.created_at
                THEN
                    RAISE EXCEPTION 'External identity transaction identity is immutable';
                END IF;
                IF NOT (
                    (OLD.status = 'pending' AND NEW.status IN ('processing', 'expired', 'revoked'))
                    OR (OLD.status = 'processing' AND NEW.status IN ('completed', 'failed'))
                ) THEN
                    RAISE EXCEPTION 'External identity transaction transition is invalid';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::statement(
            'CREATE TRIGGER external_identity_transactions_guard_update '.
            'BEFORE UPDATE ON external_identity_transactions FOR EACH ROW '.
            'EXECUTE FUNCTION v2_external_identity_guard_transaction()'
        );
        DB::statement(
            'CREATE TRIGGER external_identity_transactions_guard_delete '.
            'BEFORE DELETE ON external_identity_transactions FOR EACH ROW '.
            'EXECUTE FUNCTION v2_external_identity_guard_transaction()'
        );
        DB::statement(
            'CREATE TRIGGER external_identity_transactions_guard_truncate '.
            'BEFORE TRUNCATE ON external_identity_transactions FOR EACH STATEMENT '.
            'EXECUTE FUNCTION v2_external_identity_guard_transaction()'
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_external_identity_protect_account()
            RETURNS trigger AS $$
            BEGIN
                IF TG_OP IN ('DELETE', 'TRUNCATE') THEN
                    RAISE EXCEPTION 'External identity account deletion is forbidden';
                END IF;
                IF NEW.public_id <> OLD.public_id
                    OR NEW.user_id <> OLD.user_id
                    OR NEW.provider <> OLD.provider
                    OR NEW.issuer <> OLD.issuer
                    OR NEW.subject_hash <> OLD.subject_hash
                    OR NEW.linked_at <> OLD.linked_at
                    OR NEW.created_at <> OLD.created_at
                    OR (OLD.revoked_at IS NOT NULL AND NEW.revoked_at IS DISTINCT FROM OLD.revoked_at)
                    OR (
                        NEW.last_authenticated_at IS NOT NULL
                        AND OLD.last_authenticated_at IS NOT NULL
                        AND NEW.last_authenticated_at < OLD.last_authenticated_at
                    )
                THEN
                    RAISE EXCEPTION 'External identity account binding is immutable';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::statement(
            'CREATE TRIGGER external_identity_accounts_protect_update '.
            'BEFORE UPDATE ON external_identity_accounts FOR EACH ROW '.
            'EXECUTE FUNCTION v2_external_identity_protect_account()'
        );
        DB::statement(
            'CREATE TRIGGER external_identity_accounts_protect_delete '.
            'BEFORE DELETE ON external_identity_accounts FOR EACH ROW '.
            'EXECUTE FUNCTION v2_external_identity_protect_account()'
        );
        DB::statement(
            'CREATE TRIGGER external_identity_accounts_protect_truncate '.
            'BEFORE TRUNCATE ON external_identity_accounts FOR EACH STATEMENT '.
            'EXECUTE FUNCTION v2_external_identity_protect_account()'
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_external_identity_reject_history_mutation()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'External identity history is append-only';
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::statement(
            'CREATE TRIGGER external_identity_account_histories_reject_mutation '.
            'BEFORE UPDATE OR DELETE ON external_identity_account_histories FOR EACH ROW '.
            'EXECUTE FUNCTION v2_external_identity_reject_history_mutation()'
        );
        DB::statement(
            'CREATE TRIGGER external_identity_account_histories_reject_truncate '.
            'BEFORE TRUNCATE ON external_identity_account_histories FOR EACH STATEMENT '.
            'EXECUTE FUNCTION v2_external_identity_reject_history_mutation()'
        );
    }
};
