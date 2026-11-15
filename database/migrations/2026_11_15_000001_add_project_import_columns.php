<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Метка помещения на плане (метры этажа): из экспликации проекта, пока контур не обведен.
        Schema::table('rooms', function (Blueprint $table) {
            $table->decimal('label_x', 10, 3)->nullable()->after('area_m2');
            $table->decimal('label_y', 10, 3)->nullable()->after('label_x');
        });
        // Откуда единица пришла при импорте из проекта: повторный импорт ее не дублирует.
        Schema::table('assets', function (Blueprint $table) {
            $table->string('source_ref', 64)->nullable()->unique()->after('it_device_id');
        });
    }

    public function down(): void
    {
        Schema::table('assets', fn (Blueprint $table) => $table->dropColumn('source_ref'));
        Schema::table('rooms', fn (Blueprint $table) => $table->dropColumn(['label_x', 'label_y']));
    }
};
