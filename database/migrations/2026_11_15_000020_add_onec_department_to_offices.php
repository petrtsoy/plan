<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Подразделение филиала в 1С и наименование единицы по учету.
 *
 * По подразделению onec:sync раскладывает основные средства по филиалам.
 * Единственное поле филиала, которое заполняют руками (остальное – зеркало HR): сопоставить
 * подразделение 1С с филиалом HR автоматически нечем. В HR такое сопоставление есть, но в
 * контракте /api/it/offices его не отдают – см. PLAN.md, этап 4.
 *
 * Наименование по учету – отдельным полем, а не в assets.name: названием единицу зовут в
 * списках и на плане, и ночной синк его затирать не должен (в supply так же – номенклатура
 * держит onec_name отдельно от своего названия).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->string('onec_department_ref', 64)->nullable()->index()->after('address');
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->string('accounting_name')->nullable()->after('accounting_ref');
        });
    }

    public function down(): void
    {
        Schema::table('assets', fn (Blueprint $table) => $table->dropColumn('accounting_name'));

        Schema::table('offices', function (Blueprint $table) {
            $table->dropIndex(['onec_department_ref']);
            $table->dropColumn('onec_department_ref');
        });
    }
};
