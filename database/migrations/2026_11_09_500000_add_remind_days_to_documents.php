<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** How many days before its expiry date a document starts to be flagged (the bell, the Expiring list). Empty = the default. */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedSmallInteger('remind_days')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('remind_days');
        });
    }
};
