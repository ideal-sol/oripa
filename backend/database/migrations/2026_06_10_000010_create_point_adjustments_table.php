<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('point_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('adjustment_type');
            $table->string('point_type')->nullable();
            $table->integer('amount');
            $table->timestamp('expire_at')->nullable();
            $table->text('reason');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['admin_user_id', 'created_at']);
        });

        DB::statement("ALTER TABLE point_adjustments ADD CONSTRAINT point_adjustments_type_check CHECK (adjustment_type IN ('grant', 'deduct'))");
        DB::statement("ALTER TABLE point_adjustments ADD CONSTRAINT point_adjustments_point_type_check CHECK (point_type IS NULL OR point_type IN ('paid', 'free'))");
        DB::statement('ALTER TABLE point_adjustments ADD CONSTRAINT point_adjustments_amount_positive CHECK (amount > 0)');
        DB::statement("ALTER TABLE point_adjustments ADD CONSTRAINT point_adjustments_expire_rule_check CHECK ((adjustment_type = 'grant' AND point_type = 'free' AND expire_at IS NOT NULL) OR NOT (adjustment_type = 'grant' AND point_type = 'free'))");
        DB::statement("ALTER TABLE point_adjustments ADD CONSTRAINT point_adjustments_grant_point_type_required CHECK ((adjustment_type = 'grant' AND point_type IS NOT NULL) OR adjustment_type <> 'grant')");
    }

    public function down(): void
    {
        Schema::dropIfExists('point_adjustments');
    }
};
