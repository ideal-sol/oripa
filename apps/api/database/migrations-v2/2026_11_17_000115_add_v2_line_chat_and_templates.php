<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE line_conversations (
                id bigserial PRIMARY KEY,
                public_id uuid NOT NULL UNIQUE,
                line_bot_id bigint NOT NULL REFERENCES line_bots(id),
                subject_hash varchar(64) NOT NULL,
                user_id bigint REFERENCES users(id),
                status varchar(8) NOT NULL DEFAULT 'open' CHECK (status::text IN ('open','closed')),
                revision bigint NOT NULL DEFAULT 1 CHECK (revision > 0),
                last_message_at timestamptz NOT NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE(line_bot_id,subject_hash),
                UNIQUE(id,line_bot_id)
            );
            CREATE INDEX line_conversation_recent ON line_conversations(line_bot_id,last_message_at DESC,id DESC);
            CREATE TABLE line_chat_messages (
                id bigserial PRIMARY KEY,
                public_id uuid NOT NULL UNIQUE,
                conversation_id bigint NOT NULL,
                line_bot_id bigint NOT NULL,
                authentication_revision bigint NOT NULL,
                direction varchar(8) NOT NULL CHECK (direction::text IN ('inbound','outbound')),
                line_message_id varchar(128),
                request_key uuid UNIQUE,
                request_hash varchar(64),
                type varchar(16) NOT NULL CHECK (type::text IN ('text','sticker','image','video','audio','file','location','other','messages')),
                body_ciphertext text,
                unsent boolean NOT NULL DEFAULT false,
                read_at timestamptz,
                media_state varchar(24) NOT NULL DEFAULT 'none' CHECK (media_state::text IN ('none','fetching','stored','unavailable','pending_external','cancelled')),
                media_key varchar(256),
                media_mime varchar(128),
                media_bytes bigint CHECK (media_bytes > 0 AND media_bytes <= 8388608),
                sent_by bigint REFERENCES admins(id),
                batch_id bigint UNIQUE REFERENCES line_delivery_batches(id),
                received_at timestamptz NOT NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                UNIQUE(line_bot_id,line_message_id),
                FOREIGN KEY(conversation_id,line_bot_id) REFERENCES line_conversations(id,line_bot_id),
                CHECK (NOT unsent OR (body_ciphertext IS NULL AND media_key IS NULL)),
                CHECK ((media_state = 'stored') = (media_key IS NOT NULL)),
                CHECK ((direction = 'outbound') = (batch_id IS NOT NULL AND sent_by IS NOT NULL AND request_key IS NOT NULL)),
                CHECK (direction <> 'inbound' OR line_message_id IS NOT NULL)
            );
            CREATE INDEX line_chat_history ON line_chat_messages(conversation_id,received_at DESC,id DESC);
            CREATE INDEX line_chat_unread ON line_chat_messages(conversation_id,id) WHERE direction='inbound' AND read_at IS NULL AND NOT unsent;
            CREATE TABLE line_chat_events (
                line_bot_id bigint NOT NULL REFERENCES line_bots(id),
                event_id varchar(128) NOT NULL,
                message_id bigint NOT NULL REFERENCES line_chat_messages(id),
                event_type varchar(8) NOT NULL CHECK (event_type::text IN ('message','unsend')),
                PRIMARY KEY(line_bot_id,event_id)
            );
            CREATE TABLE line_template_categories (
                id uuid PRIMARY KEY,
                line_bot_id bigint NOT NULL REFERENCES line_bots(id),
                name varchar(100) NOT NULL,
                sort_order integer NOT NULL DEFAULT 0 CHECK (sort_order BETWEEN 0 AND 10000),
                revision bigint NOT NULL DEFAULT 1 CHECK (revision > 0),
                UNIQUE(id,line_bot_id)
            );
            CREATE TABLE line_message_templates (
                id uuid PRIMARY KEY,
                line_bot_id bigint NOT NULL REFERENCES line_bots(id),
                category_id uuid,
                name varchar(100) NOT NULL,
                messages_ciphertext text NOT NULL,
                revision bigint NOT NULL DEFAULT 1 CHECK (revision > 0),
                created_by bigint NOT NULL REFERENCES admins(id),
                updated_by bigint NOT NULL REFERENCES admins(id),
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                FOREIGN KEY(category_id,line_bot_id) REFERENCES line_template_categories(id,line_bot_id) ON DELETE RESTRICT
            );
            CREATE INDEX line_templates_category ON line_message_templates(line_bot_id,category_id,id);
            CREATE OR REPLACE FUNCTION v2_line_chat_history_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP <> 'UPDATE' THEN RAISE EXCEPTION 'LINE chat history must be retained'; END IF;
                IF (NEW.id,NEW.public_id,NEW.conversation_id,NEW.line_bot_id,NEW.authentication_revision,
                    NEW.direction,NEW.line_message_id,NEW.request_key,NEW.request_hash,NEW.type,NEW.sent_by,NEW.batch_id,NEW.received_at,NEW.created_at)
                   IS DISTINCT FROM
                   (OLD.id,OLD.public_id,OLD.conversation_id,OLD.line_bot_id,OLD.authentication_revision,
                    OLD.direction,OLD.line_message_id,OLD.request_key,OLD.request_hash,OLD.type,OLD.sent_by,OLD.batch_id,OLD.received_at,OLD.created_at)
                   OR (OLD.unsent AND NOT NEW.unsent)
                   OR (NEW.body_ciphertext IS DISTINCT FROM OLD.body_ciphertext AND NOT NEW.unsent)
                   OR (OLD.direction='outbound' AND NEW.unsent)
                THEN RAISE EXCEPTION 'LINE chat identity and content are immutable'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER line_chat_history_guard BEFORE UPDATE OR DELETE ON line_chat_messages
                FOR EACH ROW EXECUTE FUNCTION v2_line_chat_history_guard();
            CREATE TRIGGER line_chat_truncate_guard BEFORE TRUNCATE ON line_chat_messages
                FOR EACH STATEMENT EXECUTE FUNCTION v2_line_chat_history_guard();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only migration; retain LINE chat history.');
    }
};
