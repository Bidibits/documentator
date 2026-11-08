<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * users.role: one of the roles in config/documentator.php (viewer, editor, admin). Every account that exists when
     * this runs could do everything before roles existed, so each becomes an admin; new accounts start as a viewer
     * unless the person making them chooses otherwise.
     *
     * users.must_change_password: set when an admin makes the account or sets its password, cleared when the person
     * chooses their own at first sign-in.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 16)->default('viewer');
            $table->boolean('must_change_password')->default(false);
        });

        DB::table('users')->update(['role' => 'admin']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'must_change_password']);
        });
    }
};
