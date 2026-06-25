<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('sms_verified_at')->nullable()->after('email_verified_at');
        });

        Schema::create('sms_verification_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('phone_number', 32);
            $table->string('purpose', 32)->default('registration');
            $table->string('status', 32)->default('pending');
            $table->string('code_hash');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->unsignedSmallInteger('resend_count')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['phone_number', 'status']);
            $table->index(['purpose', 'status']);
            $table->index('expires_at');
        });

        DB::statement("ALTER TABLE sms_verification_codes ADD CONSTRAINT sms_verification_codes_purpose_check CHECK (purpose IN ('registration', 'phone_change'))");
        DB::statement("ALTER TABLE sms_verification_codes ADD CONSTRAINT sms_verification_codes_status_check CHECK (status IN ('pending', 'verified', 'expired', 'canceled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_verification_codes');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('sms_verified_at');
        });
    }
};
