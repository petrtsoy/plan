<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Варианты расстановки на этаже – примерка до покупки или переезда. Позиция варианта –
 * существующая единица (asset_id: переставить), модель из справочника (model_id: купить)
 * или потребность проекта (need_id: "нужен анализатор", модель еще не выбрана).
 * Утвержденный вариант настоящего здания переносится в assets через AssetMover.
 *
 * Варианты проекта (project_id) лежат на этажах его зданий-кандидатов (и, если сравниваем
 * с тем, что есть, – на этажах настоящих зданий). Что нужно разместить – project_needs;
 * сравнение зданий – сколько потребностей поместилось в каждом.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Что нужно разместить по проекту – абстрактно: категория и количество, модель или
        // габариты – если уже известны, требования к помещению – если важны.
        Schema::create('project_needs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('model_id')->nullable()->constrained('asset_models')->nullOnDelete();
            // Существующая единица, которую повезем (переезд), – вместо модели.
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label');
            $table->unsignedSmallInteger('quantity')->default(1);
            // Без модели: примерные габариты, мм, и требования (каталог Specs) – для примерки.
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('depth_mm')->nullable();
            $table->json('requirements')->nullable();
            // Сколько нужно площади под помещение целиком (кабинет врача, склад), м², – вместо габаритов.
            $table->decimal('area_m2', 8, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('layouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('floor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
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
            $table->foreignId('need_id')->nullable()->constrained('project_needs')->cascadeOnDelete();
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
        Schema::dropIfExists('project_needs');
    }
};
