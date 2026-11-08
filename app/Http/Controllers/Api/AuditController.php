<?php

namespace App\Http\Controllers\Api;

use App\Audits\ChangeReader;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    /**
     * Every recorded change to a record, newest first, one page at a time (100), the ones that match the filters.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(ChangeReader::search($request->only(['table', 'record', 'person', 'from', 'to', 'page'])));
    }
}
