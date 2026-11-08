<?php

namespace App\Documents;

use App\Exceptions\ApiError;
use App\Models\DocumentFile;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\ChangeLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Archiving a version (never a current one): its files are packed into one zip and the loose copies removed, to save
 * space. The files stay viewable and downloadable, only slower, because they are read out of the zip. Restoring unpacks
 * them to where they were. Nothing is removed until the zip has been written and every file in it checked against its
 * SHA-256, and a restore checks again before the zip goes.
 */
final class Archive
{
    /** The name of a file inside its zip: its id and extension, so two files with one name never clash. */
    public static function entryName(DocumentFile $file): string
    {
        return $file->id.'.'.pathinfo($file->storage_path, PATHINFO_EXTENSION);
    }

    public static function archive(DocumentVersion $version, ?User $user, string $source = ChangeLog::MANUAL): void
    {
        $version->loadMissing(['document', 'files']);
        $document = $version->document;
        if ((int) $document->current_version_id === (int) $version->id) {
            throw new ApiError(409, 'current_version');
        }
        if ($version->archived_at !== null) {
            throw new ApiError(409, 'already_archived');
        }

        $disk = Storage::disk('local');
        foreach ($version->files as $file) {
            if (! $disk->exists($file->storage_path)) {
                throw new ApiError(409, 'file_missing', ['file' => $file->original_name]);
            }
        }

        $zipPath = 'archive/'.$document->id.'/ver-'.$version->rev_no.'-'.Str::uuid().'.zip';
        $disk->makeDirectory('archive/'.$document->id);
        $zipFile = $disk->path($zipPath);

        try {
            $zip = new ZipArchive;
            if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new ApiError(500, 'archive_failed');
            }
            foreach ($version->files as $file) {
                $zip->addFile($disk->path($file->storage_path), self::entryName($file));
            }
            if (! $zip->close()) {
                throw new ApiError(500, 'archive_failed');
            }
            self::verify($zipFile, $version);

            $size = (int) filesize($zipFile);
            $at = now()->utc();
            DB::transaction(function () use ($version, $user, $zipPath, $size, $at, $document, $source) {
                $version->forceFill([
                    'archived_at' => $at, 'archived_by' => $user?->id, 'archive_path' => $zipPath, 'archive_size' => $size,
                ])->save();
                ChangeLog::updated('document_versions', $version->id, ['archived_at' => null, 'archived_by' => null],
                    ['archived_at' => $at->format('Y-m-d H:i:s'), 'archived_by' => $user?->id], $user, $source);
                AuditLog::record($user, 'version.archived', 'Ver '.$version->rev_no.($user ? '' : ' (by an archive rule)'),
                    username: $user ? null : 'system', entityType: 'document', entityId: $document->code);
            });
        } catch (\Throwable $e) {
            @unlink($zipFile);
            throw $e;
        }

        // only now, with the zip checked and the record saved, do the loose copies go
        foreach ($version->files as $file) {
            $disk->delete($file->storage_path);
        }
    }

    public static function restore(DocumentVersion $version, ?User $user, string $source = ChangeLog::MANUAL): void
    {
        $version->loadMissing(['document', 'files']);
        if ($version->archived_at === null) {
            throw new ApiError(409, 'not_archived');
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($version->archive_path)) {
            throw new ApiError(409, 'archive_missing');
        }

        $zip = new ZipArchive;
        if ($zip->open($disk->path($version->archive_path)) !== true) {
            throw new ApiError(500, 'archive_failed');
        }

        $written = [];
        try {
            foreach ($version->files as $file) {
                $stream = $zip->getStream(self::entryName($file));
                if ($stream === false) {
                    throw new ApiError(500, 'archive_failed');
                }
                $disk->makeDirectory(dirname($file->storage_path));
                $disk->put($file->storage_path, $stream);
                fclose($stream);
                $written[] = $file->storage_path;
                if (hash_file('sha256', $disk->path($file->storage_path)) !== $file->sha256) {
                    throw new ApiError(500, 'archive_failed');
                }
            }
        } catch (\Throwable $e) {
            $zip->close();
            foreach ($written as $path) {
                $disk->delete($path);
            }
            throw $e;
        }
        $zip->close();

        $was = $version->archived_at->format('Y-m-d H:i:s');
        $by = $version->archived_by;
        $path = $version->archive_path;
        $restoredAt = now()->utc();
        DB::transaction(function () use ($version, $user, $was, $by, $source, $restoredAt) {
            $version->forceFill(['archived_at' => null, 'archived_by' => null, 'archive_path' => null, 'archive_size' => null, 'restored_at' => $restoredAt])->save();
            // restored_at keeps the archive rules from undoing a restore the same night
            ChangeLog::updated('document_versions', $version->id, ['archived_at' => $was, 'archived_by' => $by, 'restored_at' => null],
                ['archived_at' => null, 'archived_by' => null, 'restored_at' => $restoredAt->format('Y-m-d H:i:s')], $user, $source);
            AuditLog::record($user, 'version.restored', 'Ver '.$version->rev_no, entityType: 'document', entityId: $version->document->code);
        });
        $disk->delete($path);
    }

    /** Every file in the zip must hash to what was recorded when it was uploaded. */
    private static function verify(string $zipFile, DocumentVersion $version): void
    {
        $zip = new ZipArchive;
        if ($zip->open($zipFile) !== true) {
            throw new ApiError(500, 'archive_failed');
        }
        foreach ($version->files as $file) {
            $stream = $zip->getStream(self::entryName($file));
            if ($stream === false) {
                $zip->close();
                throw new ApiError(500, 'archive_failed');
            }
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            fclose($stream);
            if (hash_final($hash) !== $file->sha256) {
                $zip->close();
                throw new ApiError(500, 'archive_failed');
            }
        }
        $zip->close();
    }
}
