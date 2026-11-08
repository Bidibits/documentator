<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A version can be archived: its files are packed into one zip (archive_path, archive_size bytes) and the loose
     * copies removed. The document_files rows stay, so name, size and SHA-256 are still known and a restore can check
     * what it unpacks. A current version is never archived (the app refuses).
     */
    public function up(): void
    {
        Schema::table('document_versions', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->index();
            $table->unsignedBigInteger('archived_by')->nullable();
            $table->string('archive_path')->nullable();
            $table->unsignedBigInteger('archive_size')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('document_versions', function (Blueprint $table) {
            $table->dropColumn(['archived_at', 'archived_by', 'archive_path', 'archive_size']);
        });
    }
};
