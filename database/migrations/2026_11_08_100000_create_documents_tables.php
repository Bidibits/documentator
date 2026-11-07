<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One document = one record with one code. Every upload of it is a version (Rev 0, 1, ...), and a version holds
     * one or more files, so a stapled packet stays one document. The code is free text that the app only suggests a
     * pattern for (TSI-YY-MM-DD-NN); it is unique whatever its case.
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $code = $table->string('code', 64);
            if (DB::getDriverName() === 'sqlite') {
                $code->collation('nocase');
            }
            $table->string('title', 200);
            $table->string('doc_type', 64)->nullable();
            $table->string('department', 100)->nullable();
            $table->text('description')->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('location_note', 200)->nullable();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();

            $table->unique('code');
            $table->index('department');
        });

        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('rev_no');
            $table->string('change_note', 500)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('created_at');

            $table->unique(['document_id', 'rev_no']);
        });

        Schema::create('document_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('document_versions')->cascadeOnDelete();
            $table->string('original_name', 190);
            $table->string('storage_path');
            $table->string('mime', 127);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_files');
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
    }
};
