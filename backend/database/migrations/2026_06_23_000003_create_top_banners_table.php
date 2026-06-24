<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('top_banners', function (Blueprint $table): void {
            $table->id();
            $table->string('image_url');
            $table->string('link_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('top_banners');
    }
};
