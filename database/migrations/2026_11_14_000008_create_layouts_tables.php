<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Варианты расстановки на этаже – примерка до покупки или переезда. Позиция варианта –
 * либо существующая единица (asset_id: переставить), либо модель из справочника
 * (model_id: собираемся купить). Утвержденный вариант переносится в assets через AssetMover.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('layouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('floor_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('status', 16)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('layout_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('layout_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('model_id')->nullable()->constrained('asset_models')->restrictOnDelete();
            $table->string('label')->nullable();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('x', 10, 3);
            $table->decimal('y', 10, 3);
            $table->decimal('rotation', 6, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('layout_items');
        Schema::dropIfExists('layouts');
    }
};
