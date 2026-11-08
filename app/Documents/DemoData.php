<?php

namespace App\Documents;

use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\DocumentVersion;
use App\Models\Folder;
use App\Models\User;
use App\Support\ChangeLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Sample documents to try the app with, on any site (php artisan documentator:demo): fifteen documents in a small tree of
 * folders, with real files of different kinds (PDF, Word, Excel, CSV, text, a picture, a drawing) and deadlines all over:
 * expired, today, in three days, in a month, in a year, none, one with its own "tell me 60 days before", and one that was
 * renewed. Every code starts with DEMO-, so they are easy to find and `--remove` takes exactly those away again.
 * Dates are relative to today, so running it again later gives fresh deadlines.
 */
final class DemoData
{
    public const PREFIX = 'DEMO-';

    /** name, icon, color, parent name */
    private const FOLDERS = [
        ['Permits', 'binder', '#E5484D', null],
        ['Contracts', 'drawers', '#0091FF', null],
        ['2026', 'folder', '#5B5BD6', 'Contracts'],
        ['HR', 'cabinet', '#30A46C', null],
        ['Finance', 'pedestal', '#F76B15', null],
        ['Policies', 'folder', '#8B8D98', null],
    ];

    /**
     * code suffix, title, type, folder, days from today to expiry (null: none), remind days (null: the default), files
     * (name => kind), physical location. A "renewed" document is made with a first version that ran out before.
     */
    private const DOCUMENTS = [
        ['01', 'Business permit 2026', 'Permit / License', 'Permits', -45, null, ['business-permit-2026.pdf' => 'pdf'], 'Cabinet 1, shelf A'],
        ['02', 'Fire safety certificate', 'Certificate', 'Permits', -1, null, ['fire-safety-certificate.pdf' => 'pdf'], 'Cabinet 1, shelf A'],
        ['03', 'Sanitary permit', 'Permit / License', 'Permits', 0, null, ['sanitary-permit.pdf' => 'pdf'], 'Cabinet 1, shelf B'],
        ['04', 'Warehouse lease agreement', 'Contract', '2026', 3, 7, ['warehouse-lease.docx' => 'docx', 'lease-map.png' => 'png'], 'Cabinet 2, drawer 1'],
        ['05', 'Packaging supplier contract', 'Contract', 'Contracts', 10, 14, ['supplier-contract.pdf' => 'pdf'], 'Cabinet 2, drawer 2'],
        ['06', 'Company insurance policy', 'Policy', 'Finance', 25, null, ['insurance-policy.pdf' => 'pdf'], 'Finance safe'],
        ['07', 'Accounting software license', 'License', 'Finance', 45, 60, ['software-license.txt' => 'txt'], 'Digital only'],
        ['08', 'Delivery van registration', 'Certificate', null, 45, null, ['van-registration.pdf' => 'pdf'], 'Glove box and cabinet 3'],
        ['09', 'Annual audit engagement', 'Contract', 'Contracts', 120, null, ['audit-engagement.xlsx' => 'xlsx'], ''],
        ['10', 'Trademark registration', 'Certificate', 'Permits', 400, null, ['trademark-certificate.pdf' => 'pdf', 'trademark-logo.png' => 'png'], 'Cabinet 1, shelf C'],
        ['11', 'Employee handbook', 'Policy', 'Policies', null, null, ['employee-handbook.docx' => 'docx'], ''],
        ['12', "Mayor's permit (renewed)", 'Permit / License', 'Permits', 28, null, ['mayors-permit.pdf' => 'pdf'], 'Cabinet 1, shelf A', 'renewed'],
        ['13', 'Staff list 2026', 'Report', 'HR', null, null, ['staff-list-2026.csv' => 'csv'], ''],
        ['14', 'Site floor plan', 'Drawing', 'HR', null, null, ['floor-plan.dwg' => 'bin'], 'Plan room'],
        ['15', 'Data privacy certificate', 'Certificate', 'HR', 12, 30, ['data-privacy-certificate.pdf' => 'pdf'], 'Cabinet 3, shelf A'],
    ];

    /**
     * Makes the sample folders and documents that are not there yet.
     *
     * @return array{documents: int, skipped: int, folders: int}
     */
    public static function create(): array
    {
        $user = User::query()->where('role', 'admin')->orderBy('id')->first() ?? User::query()->orderBy('id')->first();
        if ($user === null) {
            throw new \RuntimeException('Make an account first (scripts\\make-account.cmd), then load the sample documents.');
        }

        $today = Carbon::today(config('documentator.timezone'));
        $folders = [];
        $newFolders = 0;
        foreach (self::FOLDERS as [$name, $icon, $color, $parentName]) {
            $parent = $parentName === null ? null : $folders[$parentName] ?? null;
            $existing = Folder::query()->where('parent_id', $parent)->get()->first(fn ($f) => mb_strtolower($f->name) === mb_strtolower($name));
            if ($existing === null) {
                $existing = Folder::create(['parent_id' => $parent, 'name' => $name, 'color' => $color, 'icon' => $icon, 'created_by' => $user->id, 'updated_by' => $user->id]);
                ChangeLog::created('folders', $existing->id, ['parent_id' => $parent, 'name' => $name, 'color' => $color, 'icon' => $icon, 'created_by' => $user->id], null, ChangeLog::AUTOMATIC);
                $newFolders++;
            }
            $folders[$name] = $existing->id;
        }

        $made = $skipped = 0;
        foreach (self::DOCUMENTS as $row) {
            [$suffix, $title, $type, $folder, $days, $remind, $files, $location] = $row;
            $code = self::PREFIX.$suffix;
            if (Document::where('code', $code)->exists()) {
                $skipped++;

                continue;
            }
            DB::transaction(function () use ($row, $code, $folders, $today, $user) {
                [, $title, $type, $folder, $days, $remind, $files, $location] = $row;
                $renewed = ($row[8] ?? null) === 'renewed';
                $expires = $days === null ? null : $today->copy()->addDays($days);
                $issued = $expires?->copy()->subYear()->addDay();

                $document = Document::create([
                    'code' => $code, 'title' => $title, 'doc_type' => $type, 'folder_id' => $folder === null ? null : $folders[$folder],
                    'remind_days' => $remind, 'description' => 'Sample document for trying the app.', 'location_note' => $location ?: null,
                    'issued_on' => $issued?->format('Y-m-d'), 'expires_on' => $expires?->format('Y-m-d'),
                    'created_by' => $user->id, 'updated_by' => $user->id,
                ]);
                DB::table('documents')->where('id', $document->id)->update(['created_at' => $today->copy()->subDays(60)->utc(), 'updated_at' => $today->copy()->subDays(2)->utc()]);

                $versions = $renewed ? [[0, $issued->copy()->subYear(), $expires->copy()->subYear(), 'First issue'], [1, $issued, $expires, 'Renewed for another year']] : [[0, $issued, $expires, null]];
                $currentId = null;
                foreach ($versions as [$rev, $from, $to, $note]) {
                    $version = DocumentVersion::create([
                        'document_id' => $document->id, 'rev_no' => $rev, 'change_note' => $note,
                        'issued_on' => $from?->format('Y-m-d'), 'expires_on' => $to?->format('Y-m-d'), 'created_by' => $user->id,
                    ]);
                    DB::table('document_versions')->where('id', $version->id)->update(['created_at' => $today->copy()->subDays(60 - $rev * 30)->utc()]);
                    foreach (array_keys($files) as $order => $name) {
                        self::file($version, $name, $files[$name], $title, $order);
                    }
                    $currentId = $version->id;
                }
                // a second version with no dates for the handbook, to show a plain version history
                if ($code === self::PREFIX.'11') {
                    $second = DocumentVersion::create(['document_id' => $document->id, 'rev_no' => 1, 'change_note' => 'Leave policy updated', 'created_by' => $user->id]);
                    DB::table('document_versions')->where('id', $second->id)->update(['created_at' => $today->copy()->subDays(10)->utc()]);
                    self::file($second, 'employee-handbook.docx', 'docx', $title.' (updated)', 0);
                    $currentId = $second->id;
                }
                $document->update(['current_version_id' => $currentId]);

                ChangeLog::created('documents', $document->id, [
                    'code' => $code, 'title' => $title, 'doc_type' => $type, 'folder_id' => $document->folder_id, 'remind_days' => $remind,
                    'issued_on' => $issued?->format('Y-m-d'), 'expires_on' => $expires?->format('Y-m-d'), 'location_note' => $location ?: null,
                    'current_version_id' => $currentId, 'created_by' => $user->id,
                ], null, ChangeLog::AUTOMATIC);
            });
            $made++;
        }

        return ['documents' => $made, 'skipped' => $skipped, 'folders' => $newFolders];
    }

    /**
     * Takes away every sample document (code DEMO-...), with its versions and files, and the sample folders that are
     * left empty. Nothing else is touched.
     *
     * @return array{documents: int, folders: int}
     */
    public static function remove(): array
    {
        $documents = Document::where('code', 'like', self::PREFIX.'%')->get();
        $disk = Storage::disk('local');
        foreach ($documents as $document) {
            DB::transaction(function () use ($document) {
                $ids = DocumentVersion::where('document_id', $document->id)->pluck('id');
                DocumentFile::whereIn('version_id', $ids)->delete();
                DocumentVersion::where('document_id', $document->id)->delete();
                ChangeLog::deleted('documents', $document->id, ['code' => $document->code, 'title' => $document->title], null, ChangeLog::AUTOMATIC);
                $document->delete();
            });
            $disk->deleteDirectory('documents/'.$document->id);
            $disk->deleteDirectory('archive/'.$document->id);
            $disk->deleteDirectory('previews/'.$document->id);
        }

        // the sample folders, deepest first, only when nothing else lives in them
        $removed = 0;
        foreach (array_reverse(self::FOLDERS) as [$name]) {
            foreach (Folder::query()->get()->filter(fn ($f) => mb_strtolower($f->name) === mb_strtolower($name)) as $folder) {
                if (! Document::where('folder_id', $folder->id)->exists() && ! Folder::where('parent_id', $folder->id)->exists()) {
                    ChangeLog::deleted('folders', $folder->id, ['name' => $folder->name, 'parent_id' => $folder->parent_id], null, ChangeLog::AUTOMATIC);
                    $folder->delete();
                    $removed++;
                }
            }
        }

        return ['documents' => $documents->count(), 'folders' => $removed];
    }

    /** Writes one sample file to storage and records it. */
    private static function file(DocumentVersion $version, string $name, string $kind, string $title, int $order): void
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $bytes = match ($kind) {
            'pdf' => self::pdf($title, $name),
            'docx' => self::docx($title),
            'xlsx' => self::xlsx($title),
            'png' => self::png(),
            'csv' => "Name,Department,Start date\nAna Reyes,HR,2024-01-08\nBen Cruz,Finance,2023-06-19\nCara Lim,Operations,2025-03-03\n",
            'txt' => "{$title}\n\nThis is a sample file for trying Documentanator.\nLicense key: SAMPLE-0000-0000-0000\n",
            default => random_bytes(512),   // a kind the viewer does not know: kept as plain bytes
        };
        $path = 'documents/'.$version->document_id.'/'.Str::uuid().'.'.$extension;
        Storage::disk('local')->put($path, $bytes);

        $file = DocumentFile::create([
            'version_id' => $version->id, 'original_name' => $name, 'storage_path' => $path,
            'mime' => config("documentator.uploads.types.{$extension}.0") ?? 'application/octet-stream',
            'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'sort_order' => $order,
        ]);
        ChangeLog::created('document_files', $file->id, ['version_id' => $version->id, 'original_name' => $name, 'mime' => $file->mime, 'size' => $file->size, 'sha256' => $file->sha256, 'sort_order' => $order], null, ChangeLog::AUTOMATIC);
    }

    /** A one-page PDF with the title on it (with a proper cross-reference table, so every viewer opens it). */
    private static function pdf(string $title, string $name): string
    {
        $esc = fn (string $s) => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
        $text = "BT /F1 22 Tf 72 700 Td ({$esc($title)}) Tj ET\nBT /F1 12 Tf 72 670 Td (Sample file: {$esc($name)}) Tj ET\nBT /F1 12 Tf 72 650 Td (Made by Documentanator for trying the app.) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($text)." >>\nstream\n{$text}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private static function docx(string $title): string
    {
        $para = fn (string $t, bool $bold = false) => '<w:p><w:r>'.($bold ? '<w:rPr><w:b/></w:rPr>' : '').'<w:t xml:space="preserve">'.htmlspecialchars($t, ENT_XML1).'</w:t></w:r></w:p>';

        return self::zip([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
                .$para($title, true).$para('This is a sample Word file made by Documentanator.').$para('Open it with View to see the plain preview, or Download it.').'</w:body></w:document>',
        ]);
    }

    private static function xlsx(string $title): string
    {
        $strings = [$title, 'Item', 'Amount', 'Audit fee', 'Travel'];
        $sst = '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="5" uniqueCount="5">'.implode('', array_map(fn ($s) => '<si><t>'.htmlspecialchars($s, ENT_XML1).'</t></si>', $strings)).'</sst>';

        return self::zip([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Fees" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>',
            'xl/sharedStrings.xml' => $sst,
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
                .'<row r="1"><c r="A1" t="s"><v>1</v></c><c r="B1" t="s"><v>2</v></c></row>'
                .'<row r="2"><c r="A2" t="s"><v>3</v></c><c r="B2"><v>45000</v></c></row>'
                .'<row r="3"><c r="A3" t="s"><v>4</v></c><c r="B3"><v>12500.5</v></c></row></sheetData></worksheet>',
        ]);
    }

    /** A small solid-color PNG, made by hand (no image library needed). */
    private static function png(): string
    {
        $w = $h = 96;
        $raw = '';
        for ($y = 0; $y < $h; $y++) {
            $raw .= "\0";
            for ($x = 0; $x < $w; $x++) {
                $raw .= chr(40 + intdiv($x * 120, $w)).chr(110 + intdiv($y * 100, $h)).chr(200);
            }
        }
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0)).$chunk('IDAT', gzcompress($raw)).$chunk('IEND', '');
    }

    /** @param  array<string, string>  $entries */
    private static function zip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'demo');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }
}
