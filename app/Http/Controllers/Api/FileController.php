<?php

namespace App\Http\Controllers\Api;

use App\Auth\Access;
use App\Auth\SessionUser;
use App\Documents\Archive;
use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Models\DocumentFile;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use ZipArchive;

class FileController extends Controller
{
    /** The kinds LibreOffice can turn into a PDF for the viewer (Word .docx and Excel are drawn in the browser instead). */
    private const CONVERTIBLE = ['doc', 'ppt', 'pptx', 'odt', 'odp', 'ods', 'rtf'];

    /**
     * The file as a PDF, for the viewer, when the server has LibreOffice (config documentator.soffice) and the file is one
     * of the kinds it converts. Same rights and the same audit as viewing a file. The PDF is made once and kept
     * (previews/<document>/<file>.pdf), since a file never changes.
     */
    public function preview(Request $request, DocumentFile $file): StreamedResponse
    {
        $user = SessionUser::resolve($request);
        $document = $file->version->document;
        if (! Access::grants($user)->can(Access::TAGS['download'], $document->department_id)) {
            throw ApiError::forbidden();
        }
        $soffice = config('documentator.soffice');
        $extension = strtolower(pathinfo($file->storage_path, PATHINFO_EXTENSION));
        if (! is_string($soffice) || $soffice === '' || ! is_executable($soffice) || ! in_array($extension, self::CONVERTIBLE, true)) {
            throw new ApiError(404, 'preview_unavailable');
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($file->version->isArchived() ? (string) $file->version->archive_path : $file->storage_path)) {
            throw new ApiError(404, 'file_missing');
        }
        $cache = "previews/{$document->id}/{$file->id}.pdf";
        if (! $disk->exists($cache)) {
            $this->convert($file, $extension, $soffice, $cache);
        }

        AuditLog::record($user, 'file.viewed', $file->original_name.' (Ver '.$file->version->rev_no.')', entityType: 'document', entityId: $document->code);
        $file->forceFill(['last_viewed_at' => now()->utc(), 'last_viewed_by' => $user->id])->save();

        return $disk->download($cache, pathinfo($file->original_name, PATHINFO_FILENAME).'.pdf', [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Runs LibreOffice on a private copy in a throwaway folder (with its own profile), and keeps the PDF it makes. */
    private function convert(DocumentFile $file, string $extension, string $soffice, string $cache): void
    {
        $dir = sys_get_temp_dir().'/documentator-preview-'.Str::uuid();
        mkdir($dir.'/profile', 0700, true);
        try {
            $source = "{$dir}/in.{$extension}";
            if (! $this->copyOut($file, $source)) {
                throw new ApiError(404, 'file_missing');
            }
            $process = new Process([$soffice, '--headless', '--norestore', '--nolockcheck', '-env:UserInstallation=file://'.$dir.'/profile',
                '--convert-to', 'pdf', '--outdir', $dir, $source], $dir, ['HOME' => $dir], null, 120);
            try {
                $process->run();
            } catch (ProcessTimedOutException) {
                throw new ApiError(422, 'preview_failed');
            }
            $pdf = "{$dir}/in.pdf";
            if (! is_file($pdf) || filesize($pdf) === 0) {
                throw new ApiError(422, 'preview_failed');
            }
            Storage::disk('local')->put($cache, (string) file_get_contents($pdf));
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** The file's bytes to a plain path: from where it was stored, or out of its archive zip. */
    private function copyOut(DocumentFile $file, string $target): bool
    {
        $disk = Storage::disk('local');
        if (! $file->version->isArchived()) {
            return copy($disk->path($file->storage_path), $target);
        }
        $zip = new ZipArchive;
        $stream = $zip->open($disk->path((string) $file->version->archive_path)) === true ? $zip->getStream(Archive::entryName($file)) : false;
        if ($stream === false) {
            return false;
        }
        $out = fopen($target, 'wb');
        stream_copy_to_stream($stream, $out);
        fclose($out);
        fclose($stream);
        $zip->close();

        return true;
    }

    /**
     * Files are never public: this is the only way to one, it needs a signed-in person, and every use is audited.
     * With ?view=1 the page is showing the file in its viewer rather than saving it, which is audited as a view.
     */
    public function download(Request $request, DocumentFile $file): StreamedResponse
    {
        $user = SessionUser::resolve($request);
        if (! Access::grants($user)->can(Access::TAGS['download'], $file->version->document->department_id)) {
            throw ApiError::forbidden();   // checked first: a person who may not open it learns nothing about its storage
        }

        $disk = Storage::disk('local');
        $archived = $file->version->isArchived();
        // an archived version's files are read out of its zip (slower); the others from where they were stored
        if (! $disk->exists($archived ? (string) $file->version->archive_path : $file->storage_path)) {
            throw new ApiError(404, 'file_missing');
        }

        $document = $file->version->document;
        $viewing = $request->boolean('view');
        AuditLog::record(
            $user,
            $viewing ? 'file.viewed' : 'file.downloaded',
            $file->original_name.' (Ver '.$file->version->rev_no.')',
            entityType: 'document',
            entityId: $document->code,
        );
        // when it was last used and by whom: shown beside the buttons and read by the archive rules
        $when = $viewing ? 'viewed' : 'downloaded';
        $file->forceFill(["last_{$when}_at" => now()->utc(), "last_{$when}_by" => $user->id])->save();

        $headers = [
            'Content-Type' => $file->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ];

        if ($archived) {
            $headers['Content-Length'] = (string) $file->size;

            return response()->streamDownload(function () use ($file, $disk) {
                $zip = new ZipArchive;
                $stream = $zip->open($disk->path($file->version->archive_path)) === true ? $zip->getStream(Archive::entryName($file)) : false;
                if ($stream === false) {
                    return;
                }
                fpassthru($stream);
                fclose($stream);
                $zip->close();
            }, $file->original_name, $headers);
        }

        return $disk->download($file->storage_path, $file->original_name, $headers);
    }
}
