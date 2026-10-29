<?php

namespace Whilesmart\AgentChat\Contracts;

use Illuminate\Http\JsonResponse;

interface ResponseFormatter
{
    public function success(mixed $data = null, int $statusCode = 200, ?string $message = null): JsonResponse;

    public function failure(string $message, int $statusCode = 400, array $errors = []): JsonResponse;
}
