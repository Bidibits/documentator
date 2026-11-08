<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Roles become records an administrator keeps (Administration > Roles) instead of three fixed bundles in the config.
     * A role holds grants: one row per (department, permission) it may use, department 0 meaning "no department". The
     * Administrator role is the one system role: it can do everything in every department and is not edited.
     *
     * The viewer and editor roles that already exist are carried over with the rights they had, in the departments that
     * exist now and for documents with no department. A department made later starts with nothing ticked, so nobody
     * reaches its documents until an administrator grants them.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $name = $table->string('key', 40);
            $table->string('name', 60);
            $table->string('description', 300)->nullable();
            $table->boolean('is_system')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique('key');
        });

        Schema::create('role_grants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('role_id')->index();
            $table->unsignedBigInteger('department_id');   // 0 = documents with no department
            $table->string('permission', 64);

            $table->unique(['role_id', 'department_id', 'permission']);
        });

        $now = now()->utc();
        $view = 'documentator.document.view';
        $download = 'documentator.file.download';
        $seed = [
            ['viewer', 'Viewer', 'Finds and reads documents, and views and downloads their files.', false, [$view, $download]],
            ['editor', 'Editor', 'Everything a viewer can, and registers documents and uploads new versions.', false,
                [$view, $download, 'documentator.document.create', 'documentator.version.create']],
            ['admin', 'Administrator', 'Everything in every department, and archive rules, audits, users, departments and roles.', true, []],
        ];
        $departments = [0, ...DB::table('departments')->pluck('id')->map(fn ($id) => (int) $id)->all()];

        foreach ($seed as [$key, $name, $description, $system, $permissions]) {
            $id = DB::table('roles')->insertGetId([
                'key' => $key, 'name' => $name, 'description' => $description, 'is_system' => $system,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($departments as $department) {
                foreach ($permissions as $permission) {
                    DB::table('role_grants')->insert(['role_id' => $id, 'department_id' => $department, 'permission' => $permission]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('role_grants');
        Schema::dropIfExists('roles');
    }
};
