<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A version remembers the dates it was valid for, so a renewed permit keeps "valid 1 Jan - 31 Dec 2025" on its old
     * version while the document itself moves on to the new dates. The current version of each existing document gets the
     * document's dates (the older ones were never recorded).
     */
    public function up(): void
    {
        Schema::table('document_versions', function (Blueprint $table) {
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
        });

        DB::table('documents')->whereNotNull('current_version_id')->orderBy('id')->each(function ($document) {
            DB::table('document_versions')->where('id', $document->current_version_id)
                ->update(['issued_on' => $document->issued_on, 'expires_on' => $document->expires_on]);
        });
    }

    public function down(): void
    {
        Schema::table('document_versions', function (Blueprint $table) {
            $table->dropColumn(['issued_on', 'expires_on']);
        });
    }
};
