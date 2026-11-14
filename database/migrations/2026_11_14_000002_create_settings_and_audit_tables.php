<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Настройки из раздела "Настройки": ключ из каталога App\Services\Settings.
        // Секреты зашифрованы APP_KEY, логотип – data: URI.
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->longText('value')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id')->default(0);
            $table->string('action', 32);
            $table->json('changes')->nullable();
            $table->string('comment')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('settings');
    }
};
