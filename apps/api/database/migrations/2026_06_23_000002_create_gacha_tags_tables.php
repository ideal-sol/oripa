<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gacha_tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('gacha_tag_assignments', function (Blueprint $table): void {
            $table->foreignId('gacha_id')->constrained('gachas')->cascadeOnDelete();
            $table->foreignId('gacha_tag_id')->constrained('gacha_tags')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['gacha_id', 'gacha_tag_id']);
            $table->index(['gacha_tag_id', 'gacha_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gacha_tag_assignments');
        Schema::dropIfExists('gacha_tags');
    }
};
