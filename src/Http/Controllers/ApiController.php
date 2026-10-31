<?php

namespace Whilesmart\AgentChat\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use Whilesmart\AgentChat\Contracts\ResponseFormatter;

class ApiController extends Controller
{
    protected function success(mixed $data = null, int $statusCode = 200, ?string $message = null): JsonResponse
    {
        return app(ResponseFormatter::class)->success($data, $statusCode, $message);
    }

    protected function failure(string $message, int $statusCode = 400, array $errors = []): JsonResponse
    {
        return app(ResponseFormatter::class)->failure($message, $statusCode, $errors);
    }

    protected function resourceClass(string $key = 'run'): string
    {
        $resourceClass = config("agent-chat.resources.{$key}");

        if (! is_a($resourceClass, JsonResource::class, true)) {
            throw new InvalidArgumentException(
                "The configured agent-chat {$key} resource must extend ".JsonResource::class.'.',
            );
        }

        return $resourceClass;
    }

    protected function resource(mixed $value): JsonResource
    {
        return app()->make($this->resourceClass(), ['resource' => $value]);
    }
}
