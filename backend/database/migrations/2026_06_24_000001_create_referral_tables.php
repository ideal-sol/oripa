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
            $table->string('referral_code', 32)->nullable()->after('email');
        });

        DB::table('users')
            ->orderBy('id')
            ->select(['id'])
            ->chunk(100, function ($users): void {
                foreach ($users as $user) {
                    DB::table('users')
                        ->where('id', $user->id)
                        ->update([
                            'referral_code' => 'LP'.strtoupper(base_convert((string) $user->id, 10, 36)).Str::upper(Str::random(6)),
                        ]);
                }
            });

        Schema::table('users', function (Blueprint $table): void {
            $table->unique('referral_code');
        });

        Schema::create('referral_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('reward_point_amount')->default(0);
            $table->unsignedInteger('reward_expiration_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('user_referrals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referrer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('referral_code', 32);
            $table->string('status')->default('pending');
            $table->unsignedInteger('reward_point_amount')->default(0);
            $table->unsignedInteger('reward_expiration_days')->nullable();
            $table->timestamp('rewarded_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();

            $table->index(['referrer_user_id', 'status']);
            $table->index(['referred_user_id', 'status']);
            $table->index(['referral_code']);
        });

        DB::statement("ALTER TABLE user_referrals ADD CONSTRAINT user_referrals_status_check CHECK (status IN ('pending', 'rewarded', 'canceled'))");
        DB::statement('ALTER TABLE user_referrals ADD CONSTRAINT user_referrals_no_self_reference CHECK (referrer_user_id <> referred_user_id)');

        DB::table('referral_settings')->insert([
            'reward_point_amount' => 0,
            'reward_expiration_days' => (int) config('oripa.free_point_expiration_days', 180),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_referrals');
        Schema::dropIfExists('referral_settings');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['referral_code']);
            $table->dropColumn('referral_code');
        });
    }
};
