<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_items', function (Blueprint $table): void {
            $table->string('status')->default('requested')->after('user_prize_id');
            $table->string('tracking_number')->nullable()->after('status');
            $table->timestamp('shipped_at')->nullable()->after('tracking_number');

            $table->index(['shipping_request_id', 'status']);
        });

        DB::statement(<<<'SQL'
            UPDATE shipping_items
            SET status = shipping_requests.status,
                tracking_number = shipping_requests.tracking_number,
                shipped_at = shipping_requests.shipped_at
            FROM shipping_requests
            WHERE shipping_items.shipping_request_id = shipping_requests.id
        SQL);

        DB::statement("ALTER TABLE shipping_items ADD CONSTRAINT shipping_items_status_check CHECK (status IN ('requested', 'packing', 'shipped', 'delivered', 'returned', 'canceled'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE shipping_items DROP CONSTRAINT IF EXISTS shipping_items_status_check');

        Schema::table('shipping_items', function (Blueprint $table): void {
            $table->dropIndex(['shipping_request_id', 'status']);
            $table->dropColumn(['status', 'tracking_number', 'shipped_at']);
        });
    }
};
