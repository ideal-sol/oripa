<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE line_delivery_settings (
                id smallint PRIMARY KEY CHECK (id = 1),
                revision bigint NOT NULL DEFAULT 1 CHECK (revision > 0),
                push_every integer NOT NULL DEFAULT 10 CHECK (push_every BETWEEN 1 AND 1000),
                push_wait_seconds integer NOT NULL DEFAULT 5 CHECK (push_wait_seconds BETWEEN 0 AND 3600),
                multicast_wait_seconds integer NOT NULL DEFAULT 0 CHECK (multicast_wait_seconds BETWEEN 0 AND 3600),
                updated_by_admin_id bigint REFERENCES admins(id), updated_at timestamptz NOT NULL DEFAULT now()
            );
            INSERT INTO line_delivery_settings(id) VALUES (1);
            CREATE TABLE line_deliveries (
                id bigserial PRIMARY KEY, public_id uuid NOT NULL UNIQUE,
                category text NOT NULL CHECK (category IN ('transactional','promotional')),
                recipient_group text NOT NULL CHECK (recipient_group IN ('linked_members','unlinked_friends','all_friends')),
                title_ciphertext text, messages_ciphertext text NOT NULL, pacing jsonb NOT NULL CHECK (
                    jsonb_typeof(pacing) = 'object' AND pacing ?& ARRAY['push_every','push_wait_seconds','multicast_wait_seconds']
                    AND (pacing->>'push_every')::integer BETWEEN 1 AND 1000
                    AND (pacing->>'push_wait_seconds')::integer BETWEEN 0 AND 3600
                    AND (pacing->>'multicast_wait_seconds')::integer BETWEEN 0 AND 3600),
                status text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','scheduled','resolving','sending','completed','completed_with_errors','cancelled','blocked_quota')),
                scheduled_at timestamptz, line_bot_id bigint REFERENCES line_bots(id), authentication_revision bigint,
                recipient_count integer NOT NULL DEFAULT 0 CHECK (recipient_count >= 0),
                revision bigint NOT NULL DEFAULT 1 CHECK (revision > 0),
                lease_owner uuid, lease_until timestamptz, last_dispatched_at timestamptz,
                created_by_admin_id bigint REFERENCES admins(id), updated_by_admin_id bigint REFERENCES admins(id),
                created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
                CHECK ((lease_owner IS NULL) = (lease_until IS NULL)),
                CHECK ((line_bot_id IS NULL) = (authentication_revision IS NULL))
            );
            CREATE INDEX line_deliveries_due ON line_deliveries(status, scheduled_at);
            CREATE TABLE line_delivery_batches (
                id bigserial PRIMARY KEY, public_id uuid NOT NULL UNIQUE,
                delivery_id bigint REFERENCES line_deliveries(id), line_bot_id bigint NOT NULL REFERENCES line_bots(id),
                authentication_revision bigint NOT NULL CHECK (authentication_revision > 0),
                category text NOT NULL CHECK (category IN ('transactional','promotional')),
                method text NOT NULL CHECK (method IN ('push','multicast')),
                source_type text NOT NULL CHECK (source_type IN ('delivery','automated','chat','manual')),
                source_key uuid NOT NULL UNIQUE, replaces_batch_id bigint REFERENCES line_delivery_batches(id),
                messages_ciphertext text NOT NULL,
                retry_key uuid NOT NULL UNIQUE CHECK (retry_key::text ~ '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'),
                payload_ciphertext text, first_attempted_at timestamptz,
                status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','sending','accepted','retrying','failed','unknown','skipped')),
                position integer NOT NULL DEFAULT 0 CHECK (position >= 0), not_before timestamptz NOT NULL,
                attempts integer NOT NULL DEFAULT 0 CHECK (attempts >= 0),
                lease_owner uuid, lease_until timestamptz, request_id varchar(128), reason_code varchar(64),
                created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE(delivery_id, position),
                CHECK ((lease_owner IS NULL) = (lease_until IS NULL)),
                CHECK ((payload_ciphertext IS NULL) = (first_attempted_at IS NULL)),
                CHECK (attempts = 0 OR payload_ciphertext IS NOT NULL),
                CHECK (status <> 'sending' OR (lease_owner IS NOT NULL AND payload_ciphertext IS NOT NULL))
            );
            CREATE INDEX line_delivery_batches_due ON line_delivery_batches(status, not_before, lease_until);
            CREATE TABLE line_delivery_recipients (
                id bigserial PRIMARY KEY, batch_id bigint NOT NULL REFERENCES line_delivery_batches(id),
                source_table text NOT NULL CHECK (source_table IN ('line_friendships','line_pending_follows','line_recovered_friends')),
                source_id bigint NOT NULL, user_id bigint REFERENCES users(id),
                recipient_group text NOT NULL CHECK (recipient_group IN ('linked_members','unlinked_friends')),
                status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','skipped')),
                UNIQUE(batch_id, source_table, source_id), UNIQUE(batch_id, user_id)
            );
            CREATE OR REPLACE FUNCTION v2_line_delivery_history_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP <> 'UPDATE' THEN RAISE EXCEPTION 'LINE delivery history is retained'; END IF;
                IF TG_TABLE_NAME = 'line_delivery_batches' THEN
                    IF NEW.status <> OLD.status AND NOT (
                        (OLD.status = 'pending' AND NEW.status IN ('sending','skipped','failed')) OR
                        (OLD.status = 'sending' AND NEW.status IN ('accepted','retrying','failed','unknown')) OR
                        (OLD.status = 'retrying' AND NEW.status IN ('sending','failed','unknown'))
                    ) THEN RAISE EXCEPTION 'Invalid LINE batch transition'; END IF;
                    IF ROW(NEW.id, NEW.public_id, NEW.line_bot_id, NEW.authentication_revision, NEW.retry_key,
                        NEW.source_type, NEW.source_key, NEW.replaces_batch_id, NEW.delivery_id, NEW.method, NEW.category, NEW.messages_ciphertext, NEW.position)
                        IS DISTINCT FROM ROW(OLD.id, OLD.public_id, OLD.line_bot_id, OLD.authentication_revision, OLD.retry_key,
                        OLD.source_type, OLD.source_key, OLD.replaces_batch_id, OLD.delivery_id, OLD.method, OLD.category, OLD.messages_ciphertext, OLD.position)
                        OR (OLD.payload_ciphertext IS NOT NULL AND ROW(NEW.payload_ciphertext, NEW.first_attempted_at) IS DISTINCT FROM ROW(OLD.payload_ciphertext, OLD.first_attempted_at))
                        OR NEW.attempts < OLD.attempts THEN RAISE EXCEPTION 'LINE request identity is immutable'; END IF;
                    IF OLD.status IN ('accepted','failed','unknown','skipped') AND NEW IS DISTINCT FROM OLD THEN
                        RAISE EXCEPTION 'LINE terminal batch is immutable';
                    END IF;
                ELSIF TG_TABLE_NAME = 'line_delivery_recipients' THEN
                    IF ROW(NEW.id,NEW.batch_id,NEW.source_table,NEW.source_id,NEW.user_id,NEW.recipient_group)
                        IS DISTINCT FROM ROW(OLD.id,OLD.batch_id,OLD.source_table,OLD.source_id,OLD.user_id,OLD.recipient_group)
                        OR EXISTS (SELECT 1 FROM line_delivery_batches WHERE id=OLD.batch_id AND payload_ciphertext IS NOT NULL)
                        THEN RAISE EXCEPTION 'LINE recipient binding is immutable'; END IF;
                ELSIF TG_TABLE_NAME = 'line_deliveries' THEN
                    IF NEW.status <> OLD.status AND NOT (
                        (OLD.status IN ('draft','scheduled') AND NEW.status IN ('draft','scheduled','resolving','cancelled')) OR
                        (OLD.status = 'resolving' AND NEW.status IN ('sending','completed','blocked_quota','cancelled')) OR
                        (OLD.status = 'sending' AND NEW.status IN ('completed','completed_with_errors','blocked_quota'))
                    ) THEN RAISE EXCEPTION 'Invalid LINE delivery transition'; END IF;
                    IF OLD.status IN ('completed','completed_with_errors','cancelled','blocked_quota') AND NEW IS DISTINCT FROM OLD THEN
                        RAISE EXCEPTION 'LINE terminal delivery is immutable'; END IF;
                    IF OLD.status NOT IN ('draft','scheduled') AND ROW(NEW.title_ciphertext,NEW.messages_ciphertext,NEW.pacing,NEW.category,NEW.recipient_group,NEW.scheduled_at)
                        IS DISTINCT FROM ROW(OLD.title_ciphertext,OLD.messages_ciphertext,OLD.pacing,OLD.category,OLD.recipient_group,OLD.scheduled_at)
                        THEN RAISE EXCEPTION 'LINE resolved delivery is immutable'; END IF;
                    IF OLD.line_bot_id IS NOT NULL AND ROW(NEW.line_bot_id,NEW.authentication_revision)
                        IS DISTINCT FROM ROW(OLD.line_bot_id,OLD.authentication_revision)
                        THEN RAISE EXCEPTION 'LINE delivery BOT binding is immutable'; END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE OR REPLACE FUNCTION v2_line_delivery_recipient_insert_guard() RETURNS trigger AS $$
            DECLARE parent line_delivery_batches;
            BEGIN
                SELECT * INTO parent FROM line_delivery_batches WHERE id=NEW.batch_id FOR UPDATE;
                IF parent.payload_ciphertext IS NOT NULL OR parent.status <> 'pending' THEN
                    RAISE EXCEPTION 'LINE frozen recipients cannot be extended';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER line_delivery_recipient_insert_guard BEFORE INSERT ON line_delivery_recipients
                FOR EACH ROW EXECUTE FUNCTION v2_line_delivery_recipient_insert_guard();
        SQL);
        foreach (['line_deliveries', 'line_delivery_batches', 'line_delivery_recipients'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_history_guard BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION v2_line_delivery_history_guard(); CREATE TRIGGER {$table}_truncate_guard BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION v2_line_delivery_history_guard();");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only LINE delivery migration');
    }
};
