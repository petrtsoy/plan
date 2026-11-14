<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Инвентаризация: при открытии в ведомость попадает все, что числится в выбранном
 * филиале (или помещениях), с ожидаемым местом и МОЛ. Комиссия с телефона отмечает
 * найденное (скан QR); незнакомое – строкой "излишек". По итогам – ведомость расхождений.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->restrictOnDelete();
            $table->string('name');
            // Приказ о проведении.
            $table->string('order_number', 64)->nullable();
            $table->date('order_date')->nullable();
            $table->string('status', 16)->default('open');
            // Ограничение: {"rooms": [...], "categories": [...]}; пусто – весь филиал.
            $table->json('scope')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')->constrained()->cascadeOnDelete();
            // Пусто – излишек, которого нет в учете (описан в note).
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            // Где числится на момент открытия и где нашли.
            $table->foreignId('expected_room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->foreignId('expected_mol_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('found_room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->string('mark', 16)->default('pending');
            $table->timestamp('checked_at')->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['inventory_id', 'mark']);
            $table->unique(['inventory_id', 'asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('inventories');
    }
};
