<?php

namespace App\Http\Controllers\Api;

use App\Auth\SessionUser;
use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Models\DocumentFile;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    /**
     * Files are never public: this is the only way to one, it needs a signed-in person, and every use is audited.
     * With ?view=1 the page is showing the file in its viewer rather than saving it, which is audited as a view.
     */
    public function download(Request $request, DocumentFile $file): StreamedResponse
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($file->storage_path)) {
            throw new ApiError(404, 'file_missing');
        }

        $document = $file->version->document;
        $user = SessionUser::resolve($request);
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

        return $disk->download($file->storage_path, $file->original_name, [
            'Content-Type' => $file->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
