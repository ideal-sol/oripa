<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('line_link_code', 32)->nullable()->after('referral_code');
            $table->string('line_user_id', 191)->nullable()->after('line_link_code');
            $table->timestamp('line_linked_at')->nullable()->after('line_user_id');
        });

        DB::table('users')
            ->whereNull('line_link_code')
            ->orderBy('id')
            ->select(['id'])
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    do {
                        $code = 'LN'.Str::upper(Str::random(10));
                    } while (DB::table('users')->where('line_link_code', $code)->exists());

                    DB::table('users')->where('id', $user->id)->update([
                        'line_link_code' => $code,
                    ]);
                }
            });

        Schema::table('users', function (Blueprint $table): void {
            $table->unique('line_link_code');
            $table->unique('line_user_id');
        });

        Schema::create('line_friend_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('friend_add_url', 2048)->nullable();
            $table->unsignedInteger('reward_point_amount')->default(0);
            $table->unsignedInteger('reward_expiration_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('auto_reply_message')->nullable();
            $table->timestamps();
        });

        Schema::create('line_friend_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('line_user_id', 191)->unique();
            $table->string('status', 32)->default('friend');
            $table->string('link_code', 32)->nullable();
            $table->unsignedInteger('reward_point_amount')->default(0);
            $table->timestamp('followed_at')->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->timestamp('rewarded_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['link_code']);
        });

        Schema::create('line_friend_link_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('line_friend_link_id')->nullable()->constrained()->nullOnDelete();
            $table->string('line_user_id', 191)->nullable();
            $table->string('event_type', 32);
            $table->string('message_text', 2048)->nullable();
            $table->string('status', 32);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['line_user_id', 'event_type']);
            $table->index(['status']);
        });

        DB::statement("ALTER TABLE line_friend_links ADD CONSTRAINT line_friend_links_status_check CHECK (status IN ('friend', 'linked', 'blocked'))");
        DB::statement("ALTER TABLE line_friend_link_events ADD CONSTRAINT line_friend_link_events_status_check CHECK (status IN ('received', 'linked', 'ignored', 'failed'))");

        DB::table('line_friend_settings')->insert([
            'id' => 1,
            'friend_add_url' => env('LINE_FRIEND_ADD_URL'),
            'reward_point_amount' => 0,
            'reward_expiration_days' => (int) config('oripa.free_point_expiration_days', 180),
            'is_active' => true,
            'auto_reply_message' => 'LINE連携コードを送信してください。',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('line_friend_link_events');
        Schema::dropIfExists('line_friend_links');
        Schema::dropIfExists('line_friend_settings');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['line_link_code']);
            $table->dropUnique(['line_user_id']);
            $table->dropColumn(['line_link_code', 'line_user_id', 'line_linked_at']);
        });
    }
};
