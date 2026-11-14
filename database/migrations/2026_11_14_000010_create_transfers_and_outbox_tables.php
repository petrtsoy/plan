<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Акты передачи и отправка во внешние системы.
 *
 * Перемещение в другой филиал или к другому МОЛ (AssetMover) создает акт передачи.
 * Акт уходит в 1С документом перемещения ОС (непроведенным – проводит бухгалтер).
 * В 1С пишет только эта система – и за перемещения ИТ-техники, сделанные в it.his.kz
 * (приходят событием). ИТ-техника, перемещенная здесь, уходит еще и в it.his.kz
 * (передача через его DeviceMover): где она и за кем – там первично. Отправка идет
 * через outbox: запись ставится в одной транзакции с перемещением, фоновая задача
 * отправляет и повторяет при сбое – перемещение не теряется, если 1С недоступна.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->date('transfer_date');
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->foreignId('move_id')->nullable()->constrained('asset_moves')->nullOnDelete();
            $table->foreignId('from_office_id')->nullable()->constrained('offices')->nullOnDelete();
            $table->foreignId('to_office_id')->nullable()->constrained('offices')->nullOnDelete();
            $table->foreignId('from_mol_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('to_mol_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('reason')->nullable();
            // Что получилось в 1С: ссылка и номер документа, проведен ли (подтягивается обратно).
            $table->string('onec_ref', 64)->nullable();
            $table->string('onec_number', 32)->nullable();
            $table->boolean('onec_posted')->default(false);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('outbox', function (Blueprint $table) {
            $table->id();
            // onec | it
            $table->string('target', 16);
            // transfer, ...: что отправляем
            $table->string('action', 32);
            $table->morphs('subject');
            $table->json('payload');
            // pending | sent | failed (failed – после всех попыток, нужен человек)
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('error')->nullable();
            // Ответ внешней системы: ключ созданного документа, id записи.
            $table->string('external_ref', 64)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox');
        Schema::dropIfExists('asset_transfers');
    }
};
