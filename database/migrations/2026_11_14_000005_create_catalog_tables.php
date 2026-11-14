<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Справочники: слои плана, категории, модели оборудования, сервисные организации.
 *
 * Слой – что включается галочкой на плане (медоборудование, лаборатория, мебель,
 * пожарная безопасность...). Категория принадлежит слою; у каждой единицы учета есть
 * категория, через нее – слой, значок и цвет.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('layers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('color', 9)->default('#64748b');
            $table->string('icon', 32)->nullable();
            $table->unsignedSmallInteger('sort')->default(100);
            // Включен на плане по умолчанию.
            $table->boolean('visible')->default(true);
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('layer_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('name');
            $table->string('icon', 32)->nullable();
            $table->string('color', 9)->nullable();
            // Медицинское изделие: в карточке поля РУ и класса риска.
            $table->boolean('is_medical')->default(false);
            // Средство измерений: в карточке поверка и номер в реестре.
            $table->boolean('is_measuring')->default(false);
            // ИТ-техника: учет ведет it.his.kz, перемещения уходят туда.
            $table->boolean('is_it')->default(false);
            $table->unsignedSmallInteger('sort')->default(100);
            $table->timestamps();
        });

        // Модель – общее для всех экземпляров: габариты, сервисные зоны, требования, сроки.
        Schema::create('asset_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('manufacturer')->nullable();
            $table->string('name');
            $table->string('country')->nullable();
            // Габариты, мм: ширина (по x), глубина (по y), высота – для плана и 3D.
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('depth_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();
            $table->decimal('weight_kg', 8, 1)->nullable();
            // Свободное место для обслуживания, мм: {"front":..,"back":..,"left":..,"right":..}.
            $table->json('clearance')->nullable();
            // Что нужно от помещения – по каталогу App\Services\Specs.
            $table->json('requirements')->nullable();
            // Регламент: {"service": 6, "verification": 12} – месяцев по видам MaintenanceKind.
            $table->json('intervals')->nullable();
            $table->string('registration_number', 64)->nullable();
            $table->string('risk_class', 4)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['manufacturer', 'name']);
        });

        Schema::create('contractors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('bin', 12)->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('email')->nullable();
            $table->string('contact_person')->nullable();
            // Аккредитация в области метрологии или лицензия на ТО медтехники.
            $table->string('license')->nullable();
            $table->string('contract_number', 64)->nullable();
            $table->date('contract_until')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contractors');
        Schema::dropIfExists('asset_models');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('layers');
    }
};
