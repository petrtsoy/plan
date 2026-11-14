<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Филиалы и сотрудники – зеркало HR (как в it.his.kz). Здесь их не заводят и не правят:
 * все приходит синхронизацией (hr:sync), hr_id – ключ связи.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hr_id')->unique();
            $table->string('name');
            $table->string('short_name')->nullable();
            $table->string('city')->nullable();
            $table->string('address')->nullable();
            $table->boolean('is_branch')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hr_id')->unique();
            // ИИН – чтобы заводить пользователя выбором из сотрудников (вход через SSO – по ИИН).
            $table->string('iin', 12)->nullable()->index();
            $table->string('full_name');
            $table->string('position')->nullable();
            $table->string('department')->nullable();
            $table->string('email')->nullable();
            $table->string('hr_status', 32)->nullable();
            $table->date('hired_at')->nullable();
            $table->date('fired_at')->nullable()->index();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index('full_name');
        });

        // Сотрудник может работать в нескольких филиалах (совмещение).
        Schema::create('employee_office', function (Blueprint $table) {
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->primary(['employee_id', 'office_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_office');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('offices');
    }
};
