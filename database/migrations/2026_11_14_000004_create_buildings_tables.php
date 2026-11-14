<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Пространство: здание – этаж – помещение, подложки этажей и зоны.
 *
 * Координаты на этаже – в метрах от его начала (x вправо, y вниз), а не в пикселях
 * картинки: подложку можно заменить (новый скан, DXF вместо PDF), и расставленное не
 * съедет – новую подложку только привязывают к тем же метрам. Контуры (помещения, зоны)
 * – JSON-список точек [[x, y], ...] в тех же метрах.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Проект – рассматриваем несколько зданий (свои или чужие, например под аренду или
        // покупку) и примеряем в них оборудование. Итог – выбрано одно здание или ни одного.
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Зачем: "Новая лаборатория в Астане", "Переезд поликлиники".
            $table->text('goal')->nullable();
            // Для какого филиала; пусто – филиала еще нет (новый город).
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
            // open – рассматриваем; chosen – выбрано здание; rejected – не выбрали ничего.
            $table->string('status', 16)->default('open')->index();
            $table->unsignedBigInteger('chosen_building_id')->nullable();
            $table->text('decision')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            // У настоящего здания филиал обязателен; у кандидата проекта может не быть.
            $table->foreignId('office_id')->nullable()->constrained()->restrictOnDelete();
            // Здание из проекта. Кандидат (is_candidate) – не наше: в списках филиала не
            // показывается, настоящие перемещения туда запрещены, только варианты расстановки.
            // Выбранное в проекте становится настоящим, ссылка на проект остается – откуда взялось.
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_candidate')->default(false)->index();
            $table->string('name');
            $table->string('address')->nullable();
            // Для сравнения кандидатов: аренда или цена, общая площадь – из объявления или договора.
            $table->decimal('total_area_m2', 10, 2)->nullable();
            $table->decimal('price', 14, 2)->nullable();
            $table->string('price_note')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('floors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained()->restrictOnDelete();
            // Номер для порядка: -1 подвал, 0 цоколь, 1, 2...
            $table->smallInteger('level')->default(1);
            $table->string('name');
            // Для 3D: высота этажа (выдавливание стен) и отметка пола.
            $table->decimal('height_m', 5, 2)->default(3);
            $table->decimal('elevation_m', 6, 2)->nullable();
            $table->timestamps();

            $table->unique(['building_id', 'level']);
        });

        // Подложка этажа. Версии хранятся: текущая одна (is_current), прежние – для истории.
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('floor_id')->constrained()->cascadeOnDelete();
            $table->string('source', 8);
            $table->string('source_path', 500);
            $table->string('source_name', 255);
            // Что показывается: PNG/WebP (или SVG из векторного PDF и DXF).
            $table->string('image_path', 500)->nullable();
            $table->unsignedInteger('width_px')->nullable();
            $table->unsignedInteger('height_px')->nullable();
            $table->unsignedSmallInteger('page')->default(1);
            // Привязка к метрам этажа: метров в пикселе, сдвиг начала и поворот картинки.
            // Пусто – масштаб еще не откалиброван: расставлять можно, мерить нельзя.
            $table->decimal('meters_per_px', 12, 8)->nullable();
            $table->decimal('offset_x', 10, 3)->default(0);
            $table->decimal('offset_y', 10, 3)->default(0);
            $table->decimal('rotation', 6, 2)->default(0);
            // Две точки и расстояние, по которым считали масштаб, – чтобы перепроверить.
            $table->json('calibration')->nullable();
            $table->boolean('is_current')->default(true);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['floor_id', 'is_current']);
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('floor_id')->constrained()->restrictOnDelete();
            $table->string('number', 32)->nullable();
            $table->string('name');
            // Назначение: процедурная, лаборатория, склад, коридор...
            $table->string('purpose', 64)->nullable();
            // Отделение из HR (employees.department) – строкой, как его отдает HR.
            $table->string('department')->nullable();
            $table->json('polygon')->nullable();
            $table->decimal('area_m2', 8, 2)->nullable();
            // Инженерные свойства по каталогу App\Services\Specs.
            $table->json('specs')->nullable();
            $table->foreignId('responsible_id')->nullable()->constrained('employees')->nullOnDelete();
            // Кабинет в it.his.kz (locations.id): куда ставить ИТ-технику там и откуда она пришла сюда.
            $table->unsignedBigInteger('it_location_id')->nullable()->unique();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['floor_id', 'number']);
        });

        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('floor_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('name');
            $table->string('color', 9)->nullable();
            $table->json('polygon');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zones');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('floors');
        Schema::dropIfExists('buildings');
        Schema::dropIfExists('projects');
    }
};
