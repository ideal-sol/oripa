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
            $table->string('referral_code', 12)->nullable();
        });

        DB::table('users')->orderBy('id')->select('id')->chunkById(100, function ($users): void {
            foreach ($users as $user) {
                DB::table('users')->where('id', $user->id)->update([
                    'referral_code' => $this->uniqueReferralCode(),
                ]);
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->unique('referral_code');
        });
        DB::statement(
            "ALTER TABLE users ADD CONSTRAINT users_referral_code_check CHECK (".
            "referral_code IS NULL OR referral_code ~ '^LP[A-Za-z0-9]{10}$')"
        );

        Schema::create('referral_point_settings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->boolean('is_enabled')->default(true);
            $table->bigInteger('referrer_point_amount')->default(0);
            $table->bigInteger('referred_user_point_amount')->default(0);
            $table->unsignedInteger('reward_expiration_days')->default(180);
            $table->unsignedBigInteger('revision')->default(1);
            $table->foreignId('updated_by_admin_id')
                ->nullable()
                ->constrained('admins')
                ->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('user_referrals', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('referrer_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('referred_user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('referral_code', 12);
            $table->string('status', 16)->default('pending');
            $table->boolean('reward_enabled');
            $table->bigInteger('referrer_point_amount');
            $table->bigInteger('referred_user_point_amount');
            $table->unsignedInteger('reward_expiration_days');
            $table->foreignId('referrer_point_operation_id')
                ->nullable()
                ->unique()
                ->constrained('point_operations')
                ->restrictOnDelete();
            $table->foreignId('referred_user_point_operation_id')
                ->nullable()
                ->unique()
                ->constrained('point_operations')
                ->restrictOnDelete();
            $table->timestampTz('rewarded_at')->nullable();
            $table->timestampTz('canceled_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->index(['referrer_user_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        DB::statement(
            'ALTER TABLE referral_point_settings ADD CONSTRAINT '.
            'referral_point_settings_singleton_check CHECK (id = 1)'
        );
        DB::statement(
            'ALTER TABLE referral_point_settings ADD CONSTRAINT '.
            'referral_point_settings_values_check CHECK ('.
            'referrer_point_amount >= 0 AND referrer_point_amount <= 1000000 AND '.
            'referred_user_point_amount >= 0 AND referred_user_point_amount <= 1000000 AND '.
            'reward_expiration_days >= 1 AND reward_expiration_days <= 3650 AND revision >= 1)'
        );
        DB::statement(
            'ALTER TABLE user_referrals ADD CONSTRAINT user_referrals_values_check CHECK ('.
            'referrer_user_id <> referred_user_id AND '.
            "referral_code ~ '^LP[A-Za-z0-9]{10}$' AND ".
            "status::text = ANY (ARRAY['pending'::text, 'rewarded'::text, 'canceled'::text]) AND ".
            'referrer_point_amount BETWEEN 0 AND 1000000 AND '.
            'referred_user_point_amount BETWEEN 0 AND 1000000 AND '.
            'reward_expiration_days BETWEEN 1 AND 3650 AND '.
            "(status = 'pending' AND rewarded_at IS NULL AND canceled_at IS NULL AND ".
            'referrer_point_operation_id IS NULL AND referred_user_point_operation_id IS NULL OR '.
            "status = 'rewarded' AND rewarded_at IS NOT NULL AND canceled_at IS NULL OR ".
            "status = 'canceled' AND rewarded_at IS NULL AND canceled_at IS NOT NULL))"
        );

        DB::table('referral_point_settings')->insert([
            'id' => 1,
            'public_id' => (string) Str::uuid7(),
            'is_enabled' => true,
            'referrer_point_amount' => 0,
            'referred_user_point_amount' => 0,
            'reward_expiration_days' => (int) config('oripa.free_point_expiration_days', 180),
            'revision' => 1,
            'created_at' => now()->startOfSecond(),
            'updated_at' => now()->startOfSecond(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_referrals');
        Schema::dropIfExists('referral_point_settings');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_referral_code_check');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['referral_code']);
            $table->dropColumn('referral_code');
        });
    }

    private function uniqueReferralCode(): string
    {
        do {
            $code = 'LP'.Str::random(10);
        } while (DB::table('users')->where('referral_code', $code)->exists());

        return $code;
    }
};
