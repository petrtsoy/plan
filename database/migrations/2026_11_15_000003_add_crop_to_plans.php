<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Рамка обрезки в долях листа (x0, y0, x1, y1) и пропорции всего листа: картинка – только этот кусок.
        Schema::table('plans', function (Blueprint $table) {
            $table->json('crop')->nullable()->after('page');
        });
    }

    public function down(): void
    {
        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn('crop'));
    }
};
