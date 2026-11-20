<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

abstract class Controller
{
    /**
     * Error response for an exception from a service that throws readable messages with an HTTP-like code
     * (4xx, or 502 when a third party couldn't be reached). Validation errors keep Laravel's 422 format. Anything
     * else (a database error, a bug, a raw API error) is logged and answered with a generic message, so technical
     * texts never reach the user.
     */
    protected function exceptionResponse(\Exception $e)
    {
        if ($e instanceof ValidationException || $e instanceof HttpResponseException) {
            throw $e;
        }

        $code = $e->getCode();

        // HTTP client exceptions carry the remote status code, but their message is the raw API response
        if (!$e instanceof HttpClientException && is_int($code) && (($code >= 400 && $code < 500) || $code === 502)) {
            return response()->json(['error' => $e->getMessage()], $code);
        }

        Log::error('Unexpected error in API request', [
            'exception' => get_class($e),
            'error' => $e->getMessage(),
        ]);

        return response()->json(['error' => 'Something went wrong on our side. Please try again in a few minutes.'], 500);
    }
}
