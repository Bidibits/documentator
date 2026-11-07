<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only: on SQLite two triggers refuse every UPDATE and DELETE, so even a bug in the app cannot rewrite
     * history. (On another database the application user would get INSERT and SELECT only, per the handover.)
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at')->index();
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->string('actor_username', 64)->nullable();
            $table->string('action', 64)->index();
            $table->string('entity_type', 64)->nullable();
            $table->string('entity_id', 64)->nullable();
            $table->string('note', 500)->default('');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs BEGIN SELECT RAISE(ABORT, 'audit_logs is append-only'); END");
            DB::unprepared("CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs BEGIN SELECT RAISE(ABORT, 'audit_logs is append-only'); END");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
