<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('phone');
            $table->text('body');
            $table->string('status')->default('new');
            $table->text('reply_body')->nullable();
            $table->foreignId('replied_by_admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('email');
        });

        DB::statement("ALTER TABLE contact_requests ADD CONSTRAINT contact_requests_status_check CHECK (status IN ('new', 'replied', 'closed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_requests');
    }
};
