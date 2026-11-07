<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * An answer for the browser: {"error": "<code>", ...extra} with an HTTP status.
 * The app shows its own message for each code.
 */
class ApiError extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public readonly int $status,
        public readonly string $error,
        public readonly array $extra = [],
    ) {
        parent::__construct($error);
    }

    public static function forbidden(string $error = 'forbidden'): self
    {
        return new self(403, $error);
    }

    public static function invalid(string $field): self
    {
        return new self(422, 'invalid', ['field' => $field]);
    }

    public function render(): JsonResponse
    {
        return response()->json(['error' => $this->error] + $this->extra, $this->status, ['Cache-Control' => 'no-store']);
    }
}
