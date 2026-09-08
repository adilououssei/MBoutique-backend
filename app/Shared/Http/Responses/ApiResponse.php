<?php

namespace App\Shared\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Builds the standard API response envelope described in
 * docs/api-conventions.md. Every controller across every module returns
 * through here (or ApiController's convenience wrappers) so the shape of
 * a response never depends on who wrote the endpoint.
 */
class ApiResponse
{
    public static function success(mixed $data = null, ?string $message = null, array $meta = [], int $status = 200): JsonResponse
    {
        $payload = $data instanceof JsonResource || $data instanceof ResourceCollection
            ? $data->response()->getData(true)
            : ['data' => $data];

        return response()->json(array_merge([
            'success' => true,
            'message' => $message,
        ], $payload, [
            'meta' => array_merge($payload['meta'] ?? [], $meta),
        ]), $status);
    }

    public static function error(string $message, array $errors = [], int $status = 400, ?string $code = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'code' => $code,
            'errors' => $errors,
        ], $status);
    }
}
