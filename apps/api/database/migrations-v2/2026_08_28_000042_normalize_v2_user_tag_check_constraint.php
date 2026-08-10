<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE user_tags DROP CONSTRAINT user_tags_values_check');
        DB::statement(<<<'SQL'
            ALTER TABLE user_tags
            ADD CONSTRAINT user_tags_values_check CHECK (
                char_length(name) >= 1
                AND char_length(name) <= 100
                AND name = btrim(name)
                AND char_length(normalized_name) >= 1
                AND char_length(normalized_name) <= 100
                AND normalized_name = btrim(normalized_name)
                AND revision >= 1
            )
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE user_tags DROP CONSTRAINT user_tags_values_check');
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
    }
};
