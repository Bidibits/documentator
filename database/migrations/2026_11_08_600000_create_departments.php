<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Departments become real records an administrator keeps (Administration > Departments), so a document points at one
     * (documents.department_id) instead of holding typed text. Every distinct department already typed on a document
     * (compared without regard to case or surrounding spaces; the first spelling wins) becomes a department, and its
     * documents are pointed at it. The old text column is then dropped.
     *
     * A department is switched off, never deleted: documents keep pointing at it, it just is not offered for new ones.
     */
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $name = $table->string('name', 100);
            if (DB::getDriverName() === 'sqlite') {
                $name->collation('nocase');
            }
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique('name');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedBigInteger('department_id')->nullable()->index();
        });

        $ids = [];
        foreach (DB::table('documents')->whereNotNull('department')->orderBy('id')->get(['id', 'department']) as $document) {
            $name = trim((string) $document->department);
            if ($name === '') {
                continue;
            }
            $key = mb_strtolower($name);
            $ids[$key] ??= DB::table('departments')->insertGetId(['name' => $name, 'active' => true, 'created_at' => now()->utc(), 'updated_at' => now()->utc()]);
            DB::table('documents')->where('id', $document->id)->update(['department_id' => $ids[$key]]);
        }

        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['department']);
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('department');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('department', 100)->nullable()->index();
        });

        foreach (DB::table('departments')->get(['id', 'name']) as $department) {
            DB::table('documents')->where('department_id', $department->id)->update(['department' => $department->name]);
        }

        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['department_id']);
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('department_id');
        });
        Schema::dropIfExists('departments');
    }
};
