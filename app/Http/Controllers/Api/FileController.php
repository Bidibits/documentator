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
        AuditLog::record(
            SessionUser::resolve($request),
            $request->boolean('view') ? 'file.viewed' : 'file.downloaded',
            $file->original_name.' (Rev '.$file->version->rev_no.')',
            entityType: 'document',
            entityId: $document->code,
        );

        return $disk->download($file->storage_path, $file->original_name, [
            'Content-Type' => $file->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
