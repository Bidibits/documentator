<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A folder can look like a folder, a two-door cabinet, a filing pedestal or a set of drawers. */
    public function up(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->string('icon', 20)->default('folder');
        });
    }

    public function down(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->dropColumn('icon');
        });
    }
};
