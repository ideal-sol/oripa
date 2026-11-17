<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('line_friend_id_runs', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('actor_public_id');
            $t->foreignId('line_bot_id')->constrained('line_bots')->restrictOnDelete();
            $t->unsignedBigInteger('authentication_revision');
            $t->string('state', 16);
            foreach (['pages', 'fetched', 'unique_count', 'applied', 'created', 'updated', 'unchanged', 'duplicates', 'filled', 'failures', 'http_status'] as $counter) $t->unsignedBigInteger($counter)->default(0);
            $t->timestampTz('created_at');
            $t->timestampTz('expires_at')->index();
        });
        Schema::create('line_friend_id_staging', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->foreignUuid('run_id')->constrained('line_friend_id_runs')->restrictOnDelete();
            $t->string('subject_hash', 64);
            $t->text('subject_ciphertext');
            $t->bigInteger('observed_at_ms');
            $t->unique(['run_id', 'subject_hash']);
            $t->index(['run_id', 'id']);
        });
        DB::statement("ALTER TABLE line_friend_id_runs ADD CHECK (state::text IN ('fetching','ready','applying','completed','failed','expired') AND authentication_revision > 0 AND expires_at > created_at AND applied = created + updated + unchanged AND applied <= unique_count AND unique_count <= fetched)");
        foreach (['pages', 'fetched', 'unique_count', 'applied', 'created', 'updated', 'unchanged', 'duplicates', 'filled', 'failures', 'http_status'] as $counter) DB::statement("ALTER TABLE line_friend_id_runs ADD CHECK ($counter >= 0)");
        DB::statement("ALTER TABLE line_friend_id_staging ADD CHECK (subject_hash ~ '^[0-9a-f]{64}$' AND length(subject_ciphertext) > 0 AND observed_at_ms >= 0)");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_line_friend_id_run_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP <> 'UPDATE' THEN RAISE EXCEPTION 'Retain D7 aggregate progress'; END IF;
                IF NEW.id <> OLD.id OR NEW.actor_public_id <> OLD.actor_public_id OR NEW.line_bot_id <> OLD.line_bot_id
                    OR NEW.authentication_revision <> OLD.authentication_revision OR NEW.created_at <> OLD.created_at
                    OR NEW.expires_at <> OLD.expires_at OR NEW.applied < OLD.applied THEN
                    RAISE EXCEPTION 'Immutable D7 run binding';
                END IF;
                IF NEW.state <> OLD.state AND NOT (
                    (OLD.state = 'fetching' AND NEW.state IN ('ready','failed','expired')) OR
                    (OLD.state IN ('ready','applying') AND NEW.state IN ('applying','completed','expired')) OR
                    (OLD.state = 'failed' AND NEW.state = 'expired')) THEN
                    RAISE EXCEPTION 'Invalid D7 transition';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER line_friend_id_run_guard BEFORE UPDATE OR DELETE ON line_friend_id_runs FOR EACH ROW EXECUTE FUNCTION v2_line_friend_id_run_guard();
            CREATE TRIGGER line_friend_id_run_truncate_guard BEFORE TRUNCATE ON line_friend_id_runs FOR EACH STATEMENT EXECUTE FUNCTION v2_line_friend_id_run_guard();
            CREATE OR REPLACE FUNCTION v2_line_friend_id_stage_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'UPDATE' THEN RAISE EXCEPTION 'Immutable D7 staged binding'; END IF;
                IF NOT EXISTS (SELECT 1 FROM line_friend_id_runs WHERE id = NEW.run_id AND state = 'fetching') THEN
                    RAISE EXCEPTION 'D7 staging requires fetching run';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER line_friend_id_stage_guard BEFORE INSERT OR UPDATE ON line_friend_id_staging FOR EACH ROW EXECUTE FUNCTION v2_line_friend_id_stage_guard();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only migration; retain D7 aggregate progress.');
    }
};
