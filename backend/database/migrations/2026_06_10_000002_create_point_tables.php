<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->bigInteger('paid_balance')->default(0);
            $table->bigInteger('free_balance')->default(0);
            $table->timestamps();
        });

        Schema::create('point_lots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('point_type');
            $table->integer('granted_amount');
            $table->integer('remaining_amount');
            $table->string('source_type');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamp('granted_at');
            $table->timestamp('expire_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'point_type', 'expire_at']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('point_ledgers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('point_lot_id')->nullable()->constrained('point_lots')->nullOnDelete();
            $table->string('point_type');
            $table->string('ledger_type');
            $table->integer('amount');
            $table->bigInteger('balance_after');
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['related_type', 'related_id']);
        });

        Schema::create('point_balance_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->date('snapshot_date')->unique();
            $table->bigInteger('paid_unused_balance')->default(0);
            $table->bigInteger('free_unused_balance')->default(0);
            $table->boolean('is_base_date')->default(false);
            $table->timestamp('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallets_paid_balance_non_negative CHECK (paid_balance >= 0)');
        DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallets_free_balance_non_negative CHECK (free_balance >= 0)');
        DB::statement("ALTER TABLE point_lots ADD CONSTRAINT point_lots_point_type_check CHECK (point_type IN ('paid', 'free'))");
        DB::statement("ALTER TABLE point_lots ADD CONSTRAINT point_lots_source_type_check CHECK (source_type IN ('purchase', 'campaign', 'minimum_guarantee', 'compensation', 'exchange'))");
        DB::statement('ALTER TABLE point_lots ADD CONSTRAINT point_lots_amounts_non_negative CHECK (granted_amount >= 0 AND remaining_amount >= 0 AND remaining_amount <= granted_amount)');
        DB::statement("ALTER TABLE point_lots ADD CONSTRAINT point_lots_expire_rule_check CHECK ((point_type = 'paid' AND expire_at IS NULL) OR (point_type = 'free' AND expire_at IS NOT NULL))");
        DB::statement("ALTER TABLE point_ledgers ADD CONSTRAINT point_ledgers_point_type_check CHECK (point_type IN ('paid', 'free'))");
        DB::statement("ALTER TABLE point_ledgers ADD CONSTRAINT point_ledgers_ledger_type_check CHECK (ledger_type IN ('purchase', 'grant', 'spend', 'expire', 'compensation', 'cancel', 'exchange'))");
        DB::statement('ALTER TABLE point_balance_snapshots ADD CONSTRAINT point_balance_snapshots_non_negative CHECK (paid_unused_balance >= 0 AND free_unused_balance >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('point_balance_snapshots');
        Schema::dropIfExists('point_ledgers');
        Schema::dropIfExists('point_lots');
        Schema::dropIfExists('wallets');
    }
};
