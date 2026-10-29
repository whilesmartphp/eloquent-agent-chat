<?php

namespace Whilesmart\AgentChat\ResponseFormatters;

use Illuminate\Http\JsonResponse;
use Whilesmart\AgentChat\Contracts\ResponseFormatter;

class DefaultResponseFormatter implements ResponseFormatter
{
    public function success(mixed $data = null, int $statusCode = 200, ?string $message = null): JsonResponse
    {
        $response = ['success' => true];

        if ($message !== null) {
            $response['message'] = $message;
        } else {
            $response['data'] = $data;
        }

        return response()->json($response, $statusCode);
    }

    public function failure(string $message, int $statusCode = 400, array $errors = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $statusCode);
    }
}
