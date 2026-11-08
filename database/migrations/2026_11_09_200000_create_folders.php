<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Folders, like a drive: a folder holds documents and other folders, as deep as people want (the app stops at 12
     * levels). A document sits in at most one folder (documents.folder_id; none = the top level). Folders only organise:
     * who may see or change a document is still decided by its department. A folder is removed only when it is empty.
     */
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $name = $table->string('name', 100);
            if (DB::getDriverName() === 'sqlite') {
                $name->collation('nocase');
            }
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedBigInteger('folder_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['folder_id']);
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('folder_id');
        });
        Schema::dropIfExists('folders');
    }
};
