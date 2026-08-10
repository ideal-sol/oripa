<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_tags', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->string('name', 100);
            $table->string('normalized_name', 100)->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestampsTz();
            $table->index(['is_active', 'id'], 'user_tags_active_cursor_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE user_tags
            ADD CONSTRAINT user_tags_values_check CHECK (
                char_length(name) BETWEEN 1 AND 100
                AND name = btrim(name)
                AND char_length(normalized_name) BETWEEN 1 AND 100
                AND normalized_name = btrim(normalized_name)
                AND revision >= 1
            )
            SQL);

        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('tag_assignment_revision')->default(1);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE users
            ADD CONSTRAINT users_tag_assignment_revision_check
            CHECK (tag_assignment_revision >= 1)
            SQL);

        Schema::create('user_tag_assignments', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_tag_id')->constrained('user_tags')->restrictOnDelete();
            $table->uuid('assigned_by_admin_public_id');
            $table->timestampTz('assigned_at');
            $table->primary(['user_id', 'user_tag_id']);
            $table->index(['user_tag_id', 'user_id'], 'user_tag_assignments_tag_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_tag_assignments');
        DB::statement(
            'ALTER TABLE users DROP CONSTRAINT IF EXISTS users_tag_assignment_revision_check'
        );
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('tag_assignment_revision');
        });
        DB::statement('ALTER TABLE user_tags DROP CONSTRAINT IF EXISTS user_tags_values_check');
        Schema::dropIfExists('user_tags');
    }
};
