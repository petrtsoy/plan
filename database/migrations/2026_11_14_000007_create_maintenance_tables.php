<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ТО, поверка, калибровка, квалификация, перезарядка огнетушителей.
 *
 * maintenance_plans – что и как часто положено делать с единицей (срок next_due_at
 * раскрашивает ее на плане); maintenance_records – что сделано, с актом или
 * свидетельством. Запись о выполнении сдвигает срок в плане.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->unsignedSmallInteger('interval_months');
            $table->foreignId('contractor_id')->nullable()->constrained()->nullOnDelete();
            $table->date('last_done_at')->nullable();
            $table->date('next_due_at')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['asset_id', 'kind']);
        });

        Schema::create('maintenance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('maintenance_plans')->nullOnDelete();
            $table->string('kind', 16);
            $table->date('performed_at');
            $table->string('result', 16)->default('ok');
            // Действует до: для поверки – из свидетельства, иначе считается по интервалу.
            $table->date('valid_until')->nullable();
            $table->foreignId('contractor_id')->nullable()->constrained()->nullOnDelete();
            // Номер акта или свидетельства о поверке.
            $table->string('document_number', 64)->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['asset_id', 'performed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_records');
        Schema::dropIfExists('maintenance_plans');
    }
};
