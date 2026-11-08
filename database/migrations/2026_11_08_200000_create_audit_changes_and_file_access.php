<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * audit_changes: one row per changed column of a record people edit (the way KVNsys's audits table works). Written in
     * the same transaction as the save, and append-only like audit_logs: on SQLite two triggers refuse UPDATE and DELETE.
     *
     * document_files gets the last time (and the person) it was viewed and downloaded. Those are read by the archive
     * rules and shown beside the buttons. They are not column-audited: every view and download is already a line in
     * audit_logs, so a row per press would only repeat it.
     */
    public function up(): void
    {
        Schema::create('audit_changes', function (Blueprint $table) {
            $table->id();
            $table->char('save_id', 36)->index();
            $table->timestamp('occurred_at')->index();
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->string('actor_username', 64)->nullable();
            $table->string('source', 10);
            $table->string('table_name', 64);
            $table->string('record_id', 64);
            $table->string('column_name', 64);
            $table->text('value_before')->nullable();
            $table->text('value_after')->nullable();
            $table->string('change_type', 16);
            $table->string('ip', 45)->nullable();

            $table->index(['table_name', 'record_id']);
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER audit_changes_no_update BEFORE UPDATE ON audit_changes BEGIN SELECT RAISE(ABORT, 'audit_changes is append-only'); END");
            DB::unprepared("CREATE TRIGGER audit_changes_no_delete BEFORE DELETE ON audit_changes BEGIN SELECT RAISE(ABORT, 'audit_changes is append-only'); END");
        }

        Schema::table('document_files', function (Blueprint $table) {
            $table->timestamp('last_viewed_at')->nullable();
            $table->unsignedBigInteger('last_viewed_by')->nullable();
            $table->timestamp('last_downloaded_at')->nullable();
            $table->unsignedBigInteger('last_downloaded_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('document_files', function (Blueprint $table) {
            $table->dropColumn(['last_viewed_at', 'last_viewed_by', 'last_downloaded_at', 'last_downloaded_by']);
        });
        Schema::dropIfExists('audit_changes');
    }
};
