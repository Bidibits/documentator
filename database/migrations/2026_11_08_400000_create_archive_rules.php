<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * archive_rules: at most one row per kind ('idle': not opened for a while; 'old': replaced by a newer version).
     * A rule is created the first time it is saved; until then it is off.
     *
     * document_versions.restored_at: when someone last took the version out of the archive. The rules leave a restored
     * version alone, so a restore is not undone by the next night's run.
     */
    public function up(): void
    {
        Schema::create('archive_rules', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16)->unique();
            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('amount');
            $table->string('unit', 8);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::table('document_versions', function (Blueprint $table) {
            $table->timestamp('restored_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('document_versions', function (Blueprint $table) {
            $table->dropColumn('restored_at');
        });
        Schema::dropIfExists('archive_rules');
    }
};
