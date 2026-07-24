<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->timestampTz('occurred_at');
            $table->date('business_date');
            $table->uuid('request_id');
            $table->string('actor_type', 16);
            $table->uuid('actor_public_id')->nullable();
            $table->string('actor_role', 32)->nullable();
            $table->string('auth_realm', 16)->nullable();
            $table->char('session_correlation_hash', 64)->nullable();
            $table->string('action_code', 128);
            $table->string('target_type', 64)->nullable();
            $table->uuid('target_public_id')->nullable();
            $table->string('outcome', 16);
            $table->string('reason_code', 64)->nullable();
            $table->string('reason_text', 500)->nullable();
            $table->jsonb('before_redacted')->nullable();
            $table->jsonb('after_redacted')->nullable();
            $table->jsonb('metadata_redacted')->default('{}');
            $table->char('ip_correlation_hash', 64)->nullable();
            $table->char('user_agent_hash', 64)->nullable();
            $table->string('hmac_key_version', 32);
            $table->char('previous_hash', 64)->nullable();
            $table->char('record_hash', 64)->unique();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['business_date', 'id']);
            $table->index(['action_code', 'occurred_at']);
            $table->index(['actor_public_id', 'occurred_at']);
            $table->index(['target_public_id', 'occurred_at']);
            $table->index('request_id');
        });

        Schema::create('audit_daily_digests', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->date('business_date')->unique();
            $table->unsignedBigInteger('record_count');
            $table->char('first_record_hash', 64);
            $table->char('last_record_hash', 64);
            $table->string('hmac_key_version', 32);
            $table->char('digest_hash', 64)->unique();
            $table->timestampTz('generated_at');
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('outbox_messages', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->string('topic', 128);
            $table->string('aggregate_type', 64);
            $table->uuid('aggregate_public_id')->nullable();
            $table->string('event_type', 128);
            $table->jsonb('payload');
            $table->string('deduplication_key', 255)->unique();
            $table->string('status', 16)->default('pending');
            $table->timestampTz('available_at');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('locked_at')->nullable();
            $table->string('locked_by', 128)->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->index(['status', 'available_at', 'id']);
            $table->index(['lease_expires_at', 'id']);
            $table->index(['aggregate_type', 'aggregate_public_id']);
        });

        DB::statement(
            "ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_actor_type_check ".
            "CHECK (actor_type::text = ANY (ARRAY[".
            "'system'::text, 'user'::text, 'admin'::text]))"
        );
        DB::statement(
            "ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_auth_realm_check ".
            "CHECK (auth_realm IS NULL OR auth_realm::text = ANY (ARRAY[".
            "'system'::text, 'user'::text, 'admin'::text]))"
        );
        DB::statement(
            "ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_outcome_check ".
            "CHECK (outcome::text = ANY (ARRAY[".
            "'success'::text, 'failure'::text, 'pending'::text]))"
        );
        DB::statement(
            "ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_hash_check CHECK (".
            "(previous_hash IS NULL OR previous_hash ~ '^[0-9a-f]{64}$') AND ".
            "record_hash ~ '^[0-9a-f]{64}$' AND ".
            "(session_correlation_hash IS NULL OR session_correlation_hash ~ '^[0-9a-f]{64}$') AND ".
            "(ip_correlation_hash IS NULL OR ip_correlation_hash ~ '^[0-9a-f]{64}$') AND ".
            "(user_agent_hash IS NULL OR user_agent_hash ~ '^[0-9a-f]{64}$'))"
        );
        DB::statement(
            "ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_json_object_check CHECK (".
            "(before_redacted IS NULL OR jsonb_typeof(before_redacted) = 'object') AND ".
            "(after_redacted IS NULL OR jsonb_typeof(after_redacted) = 'object') AND ".
            "jsonb_typeof(metadata_redacted) = 'object')"
        );
        DB::statement(
            "ALTER TABLE audit_daily_digests ADD CONSTRAINT audit_daily_digest_hash_check CHECK (".
            "first_record_hash ~ '^[0-9a-f]{64}$' AND ".
            "last_record_hash ~ '^[0-9a-f]{64}$' AND ".
            "digest_hash ~ '^[0-9a-f]{64}$' AND record_count > 0)"
        );
        DB::statement(
            "ALTER TABLE outbox_messages ADD CONSTRAINT outbox_messages_status_check ".
            "CHECK (status::text = ANY (ARRAY[".
            "'pending'::text, 'processing'::text, 'delivered'::text, 'failed'::text]))"
        );
        DB::statement(
            "ALTER TABLE outbox_messages ADD CONSTRAINT outbox_messages_payload_check ".
            "CHECK (jsonb_typeof(payload) = 'object')"
        );
        DB::statement(
            "ALTER TABLE outbox_messages ADD CONSTRAINT outbox_messages_lease_check CHECK (".
            "(status = 'processing' AND locked_at IS NOT NULL AND locked_by IS NOT NULL ".
            "AND lease_expires_at IS NOT NULL) OR ".
            "(status <> 'processing' AND locked_at IS NULL AND locked_by IS NULL ".
            "AND lease_expires_at IS NULL))"
        );
        DB::statement(
            "ALTER TABLE outbox_messages ADD CONSTRAINT outbox_messages_delivery_check CHECK (".
            "(status = 'delivered' AND delivered_at IS NOT NULL) OR ".
            "(status <> 'delivered' AND delivered_at IS NULL))"
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_reject_audit_mutation() RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'V2 audit records are append-only';
            END;
            $$;

            CREATE TRIGGER audit_logs_reject_mutation
            BEFORE UPDATE OR DELETE ON audit_logs
            FOR EACH ROW EXECUTE FUNCTION v2_reject_audit_mutation();

            CREATE TRIGGER audit_logs_reject_truncate
            BEFORE TRUNCATE ON audit_logs
            FOR EACH STATEMENT EXECUTE FUNCTION v2_reject_audit_mutation();

            CREATE TRIGGER audit_daily_digests_reject_mutation
            BEFORE UPDATE OR DELETE ON audit_daily_digests
            FOR EACH ROW EXECUTE FUNCTION v2_reject_audit_mutation();

            CREATE TRIGGER audit_daily_digests_reject_truncate
            BEFORE TRUNCATE ON audit_daily_digests
            FOR EACH STATEMENT EXECUTE FUNCTION v2_reject_audit_mutation();
        SQL);

        DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON audit_logs FROM PUBLIC');
        DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON audit_daily_digests FROM PUBLIC');
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
        Schema::dropIfExists('audit_daily_digests');
        Schema::dropIfExists('audit_logs');
        DB::statement('DROP FUNCTION IF EXISTS v2_reject_audit_mutation()');
    }
};
