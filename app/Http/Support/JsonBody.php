<?php

namespace App\Http\Support;

use App\Exceptions\ApiError;
use Illuminate\Http\Request;
use JsonException;
use stdClass;

/**
 * Reads a request body that must be one JSON object.
 *
 * Objects stay stdClass so that an empty object goes back to the browser as {} and not as [].
 */
final class JsonBody
{
    public static function object(Request $request, int $maxBytes): stdClass
    {
        if ((int) $request->header('Content-Length', '0') > $maxBytes) {
            throw new ApiError(413, 'too_large');
        }

        $text = $request->getContent();
        if (strlen($text) > $maxBytes) {
            throw new ApiError(413, 'too_large');
        }
        if (trim($text) === '') {
            throw new ApiError(400, 'body');
        }

        try {
            $value = json_decode($text, false, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new ApiError(400, 'json');
        }

        if (! $value instanceof stdClass) {
            throw new ApiError(400, 'body');
        }

        return $value;
    }

    /**
     * A string field of a request object, or the default when it is missing or not a string.
     */
    public static function string(stdClass $body, string $key, string $default = ''): string
    {
        $value = $body->{$key} ?? null;

        return is_string($value) ? $value : $default;
    }
}
