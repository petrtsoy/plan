<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Помещение и зону убирают с плана мягко: запись помечается и пропадает со всех списков,
 * но история (перемещения, акты, инвентаризация) продолжает показывать ее номер и название.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['rooms', 'zones'] as $table) {
            if (! Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->softDeletes();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['rooms', 'zones'] as $table) {
            if (Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropSoftDeletes();
                });
            }
        }
    }
};
