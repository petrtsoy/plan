<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Единица учета: медицинское и лабораторное оборудование, мебель, огнетушитель – все здесь,
 * различаются категорией. Где стоит и за кем – только через AssetMover (история в asset_moves).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('model_id')->nullable()->constrained('asset_models')->nullOnDelete();
            // Без модели (стул, огнетушитель) – название здесь; с моделью – уточнение.
            $table->string('name')->nullable();
            $table->string('inventory_number', 64)->nullable()->unique();
            $table->string('serial_number', 128)->nullable()->index();
            // Для этикетки: QR ведет на /a/{qr_code}, не раскрывая id.
            $table->string('qr_code', 32)->unique();
            $table->string('status', 16)->default('in_use')->index();

            // Где: филиал обязателен, помещение и точка на этаже – если известны.
            $table->foreignId('office_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('floor_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('x', 10, 3)->nullable();
            $table->decimal('y', 10, 3)->nullable();
            $table->decimal('rotation', 6, 2)->default(0);
            $table->string('department')->nullable();
            // За кем: материально ответственное лицо и кто работает на оборудовании.
            $table->foreignId('mol_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();

            // Медицинское изделие (категория is_medical): РУ, если отличается от модели.
            $table->string('registration_number', 64)->nullable();
            $table->date('registration_until')->nullable();
            // Средство измерений (категория is_measuring): номер в реестре ГСИ РК.
            $table->string('gsi_number', 64)->nullable();

            $table->unsignedSmallInteger('manufactured_year')->nullable();
            $table->date('commissioned_at')->nullable();
            $table->date('purchased_at')->nullable();
            $table->decimal('price', 14, 2)->nullable();
            $table->string('supplier')->nullable();
            $table->date('warranty_until')->nullable();
            // Сервисная организация по умолчанию для ТО.
            $table->foreignId('contractor_id')->nullable()->constrained()->nullOnDelete();
            // Ключ основного средства в бухгалтерии (1С) – для сверки.
            $table->string('accounting_ref', 64)->nullable()->index();
            // Устройство в it.his.kz (devices.id) – для ИТ-категорий, сопоставление по инвентарному номеру.
            $table->unsignedBigInteger('it_device_id')->nullable()->unique();
            $table->date('written_off_at')->nullable();
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['office_id', 'status']);
            $table->index('floor_id');
        });

        Schema::create('asset_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_office_id')->nullable()->constrained('offices')->nullOnDelete();
            $table->foreignId('to_office_id')->nullable()->constrained('offices')->nullOnDelete();
            $table->foreignId('from_room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->foreignId('to_room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->foreignId('from_mol_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('to_mol_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('from_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('to_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            // Из какого утвержденного варианта расстановки пришло перемещение.
            $table->unsignedBigInteger('layout_id')->nullable();
            // Где сделано: plan – здесь; it – в it.his.kz (пришло событием, обратно не отправляется).
            $table->string('origin', 8)->default('plan');
            // id перемещения в it.his.kz (device_moves.id): повторная доставка события не создаст второй акт.
            $table->unsignedBigInteger('it_move_id')->nullable()->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('comment')->nullable();
            $table->timestamp('moved_at');

            $table->index(['asset_id', 'moved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_moves');
        Schema::dropIfExists('assets');
    }
};
