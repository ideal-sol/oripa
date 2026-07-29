<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MAX_REWARD_POINT_AMOUNT = 1_000_000;

    public function up(): void
    {
        Schema::table('line_messaging_settings', function (Blueprint $table): void {
            $table->boolean('reward_enabled')->default(false)
                ->after('login_relative_path');
        });

        DB::statement(
            'ALTER TABLE line_messaging_settings '.
            'DROP CONSTRAINT line_messaging_settings_values_check'
        );
        DB::statement(
            'ALTER TABLE line_messaging_settings ADD CONSTRAINT '.
            'line_messaging_settings_values_check CHECK ('.
            'char_length(linked_follow_message) >= 1 AND '.
            'char_length(linked_follow_message) <= 1000 AND '.
            'char_length(pending_follow_message) >= 1 AND '.
            'char_length(pending_follow_message) <= 1000 AND '.
            "linked_follow_message !~ '[<>]' AND pending_follow_message !~ '[<>]' AND ".
            "login_relative_path ~ '^/[A-Za-z0-9/_?&=.-]*$' AND (".
            '(reward_enabled = false AND reward_point_amount = 0::bigint) OR '.
            '(reward_enabled = true AND reward_point_amount >= 1::bigint AND '.
            'reward_point_amount <= '.
            self::MAX_REWARD_POINT_AMOUNT.
            '::bigint)) AND reward_expiration_days >= 1::integer AND '.
            'reward_expiration_days <= 3650::integer AND revision >= 1::bigint)'
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE line_messaging_settings '.
            'DROP CONSTRAINT line_messaging_settings_values_check'
        );
        Schema::table('line_messaging_settings', function (Blueprint $table): void {
            $table->dropColumn('reward_enabled');
        });
        DB::statement(
            'ALTER TABLE line_messaging_settings ADD CONSTRAINT '.
            'line_messaging_settings_values_check CHECK ('.
            'char_length(linked_follow_message) >= 1 AND '.
            'char_length(linked_follow_message) <= 1000 AND '.
            'char_length(pending_follow_message) >= 1 AND '.
            'char_length(pending_follow_message) <= 1000 AND '.
            "linked_follow_message !~ '[<>]' AND pending_follow_message !~ '[<>]' AND ".
            "login_relative_path ~ '^/[A-Za-z0-9/_?&=.-]*$' AND ".
            'reward_point_amount >= 0::bigint AND '.
            'reward_expiration_days >= 1::integer AND '.
            'reward_expiration_days <= 3650::integer AND revision >= 1::bigint)'
        );
    }
};
