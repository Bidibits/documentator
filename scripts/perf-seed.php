<?php

/*
| Fills a THROWAWAY database with a lot of fake data (10,000 documents, 30,000 versions and files, 60 folders, 20,000 audit rows)
| so the lists and the archive rules can be timed. Run it with tinker against a scratch database, never a real one:
|
|   docker run --rm -v "<project>:/app" -w /app -e DB_DATABASE=/tmp/t.sqlite composer:2 sh -c \
|     "touch /tmp/t.sqlite && php artisan migrate --force && php artisan tinker scripts/perf-seed.php"
|
| It refuses to run when there are documents already. Sign in as kent / correct-horse.
*/
if (DB::table('documents')->exists() || DB::table('users')->exists()) {
    echo "Refusing: this database is not empty.\n";

    return;
}

use App\Models\Department;
use App\Models\User;

$u = User::factory()->create(['username' => 'kent', 'name' => 'Kent Test']);
$now = now()->utc()->format('Y-m-d H:i:s');
DB::table('folders')->insert(array_map(fn ($i) => ['name' => "Folder $i", 'parent_id' => $i > 20 ? ($i % 20) + 1 : null, 'icon' => 'folder', 'created_by' => 1, 'updated_by' => 1, 'created_at' => $now, 'updated_at' => $now], range(1, 60)));
$dept = Department::factory()->create(['name' => 'HR'])->id;
for ($chunk = 0; $chunk < 50; $chunk++) {
    $docs = [];
    for ($i = 1; $i <= 200; $i++) {
        $n = $chunk * 200 + $i;
        $docs[] = ['code' => "DOC-$n", 'title' => "Seeded document number $n lease permit", 'doc_type' => 'Contract', 'department_id' => $n % 3 ? $dept : null, 'folder_id' => $n % 5 ? ($n % 60) + 1 : null, 'expires_on' => $n % 7 ? null : now()->addDays($n % 90 - 20)->format('Y-m-d'), 'created_by' => 1, 'updated_by' => 1, 'created_at' => $now, 'updated_at' => $now];
    }
    DB::table('documents')->insert($docs);
}
$vers = [];
$files = [];
foreach (range(1, 10000) as $d) {
    foreach ([0, 1, 2] as $rev) {
        $vers[] = ['document_id' => $d, 'rev_no' => $rev, 'created_by' => 1, 'created_at' => $now];
    }
}
foreach (array_chunk($vers, 500) as $c) {
    DB::table('document_versions')->insert($c);
}
$ids = DB::table('document_versions')->orderBy('id')->get(['id', 'document_id', 'rev_no']);
$cur = [];
$fl = [];
foreach ($ids as $v) {
    $fl[] = ['version_id' => $v->id, 'original_name' => "f{$v->id}.pdf", 'storage_path' => "documents/{$v->document_id}/x{$v->id}.pdf", 'mime' => 'application/pdf', 'size' => 1000, 'sha256' => str_repeat('a', 64), 'sort_order' => 0, 'last_viewed_at' => $v->id % 4 ? null : $now];
    if ($v->rev_no === 2) {
        $cur[$v->document_id] = $v->id;
    }
}
foreach (array_chunk($fl, 500) as $c) {
    DB::table('document_files')->insert($c);
}
foreach ($cur as $d => $v) {
    DB::table('documents')->where('id', $d)->update(['current_version_id' => $v]);
}
$rows = [];
for ($i = 1; $i <= 20000; $i++) {
    $rows[] = ['save_id' => "s$i", 'occurred_at' => $now, 'actor_user_id' => 1, 'actor_username' => 'kent', 'source' => 'Manual', 'table_name' => 'documents', 'record_id' => (string) (($i % 10000) + 1), 'column_name' => 'title', 'value_before' => 'a', 'value_after' => 'b', 'change_type' => 'Updated', 'ip' => null];
}
foreach (array_chunk($rows, 500) as $c) {
    DB::table('audit_changes')->insert($c);
}
echo "seeded\n";
