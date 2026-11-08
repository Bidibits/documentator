<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A folder can have a colour (#RRGGBB); none means the app's own blue. */
    public function up(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->string('color', 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
