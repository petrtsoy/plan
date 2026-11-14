<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Файлы к записям: фото оборудования, паспорта и инструкции, регистрационные удостоверения,
 * свидетельства о поверке, акты ТО. Лежат в storage/app/private/attachments, отдаются только вошедшим.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->morphs('attachable');
            // Метка из Attachment::KINDS: фото, паспорт, РУ, свидетельство, акт...
            $table->string('kind', 32)->default('other');
            $table->string('name', 255);
            $table->string('path', 500);
            $table->string('mime', 128)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
