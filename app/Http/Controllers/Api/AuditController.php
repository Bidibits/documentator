<?php

namespace App\Http\Controllers\Api;

use App\Audits\ChangeReader;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    /**
     * Every recorded change to a record, newest first (the newest 500 that match). Until roles exist any signed-in
     * person can read it; it will need its own right then.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json(ChangeReader::search($request->only(['table', 'record', 'person', 'from', 'to'])));
    }
}
