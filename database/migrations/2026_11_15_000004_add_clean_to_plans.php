<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Чистка PDF-подложки: скрытые типы линий, заливок и текста и очищенная страница (hide, pdf).
        Schema::table('plans', function (Blueprint $table) {
            $table->json('clean')->nullable()->after('crop');
        });
    }

    public function down(): void
    {
        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn('clean'));
    }
};
