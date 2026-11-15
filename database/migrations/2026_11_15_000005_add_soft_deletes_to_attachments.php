<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Файл убирают из карточки мягко: запись помечается, сам файл в storage остается
 * (по правилам проекта ничего не удаляем физически).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('attachments', 'deleted_at')) {
            Schema::table('attachments', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('attachments', 'deleted_at')) {
            Schema::table('attachments', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
