<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_request_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipping_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('tracking_number')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['shipping_request_id', 'created_at']);
            $table->index(['admin_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_request_histories');
    }
};
