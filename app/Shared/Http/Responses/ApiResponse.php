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
 *
 * Every key is French (`succes`, `donnees`, `erreurs`, `liens`, `meta`) —
 * including the pagination block Laravel generates in English, which is
 * translated here once rather than in every controller.
 */
class ApiResponse
{
    /**
     * Laravel's pagination `links` keys → the French ones exposed by the API.
     *
     * @var array<string, string>
     */
    private const PAGINATION_LINKS = [
        'first' => 'premier',
        'last' => 'dernier',
        'prev' => 'precedent',
        'next' => 'suivant',
    ];

    /**
     * Laravel's pagination `meta` keys → the French ones exposed by the API.
     * Laravel's own `meta.links` (the page-number list, with English
     * url/label/active keys) is deliberately dropped: `liens` already
     * covers navigation, and the page count is in `derniere_page`.
     *
     * @var array<string, string>
     */
    private const PAGINATION_META = [
        'current_page' => 'page_courante',
        'last_page' => 'derniere_page',
        'per_page' => 'par_page',
        'total' => 'total',
        'from' => 'de',
        'to' => 'a',
        'path' => 'chemin',
    ];

    public static function success(mixed $data = null, ?string $message = null, array $meta = [], int $status = 200): JsonResponse
    {
        $payload = $data instanceof JsonResource || $data instanceof ResourceCollection
            ? $data->response()->getData(true)
            : ['data' => $data];

        $body = [
            'succes' => true,
            'message' => $message,
            'donnees' => $payload['data'] ?? null,
        ];

        if (isset($payload['links'])) {
            $body['liens'] = self::translateKeys($payload['links'], self::PAGINATION_LINKS);
        }

        $body['meta'] = array_merge(
            isset($payload['meta']) ? self::translateKeys($payload['meta'], self::PAGINATION_META) : [],
            $meta,
        );

        return response()->json($body, $status);
    }

    public static function error(string $message, array $errors = [], int $status = 400, ?string $code = null): JsonResponse
    {
        return response()->json([
            'succes' => false,
            'message' => $message,
            'code' => $code,
            'erreurs' => $errors,
        ], $status);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $translations
     * @return array<string, mixed>
     */
    private static function translateKeys(array $values, array $translations): array
    {
        $translated = [];

        foreach ($translations as $english => $french) {
            if (array_key_exists($english, $values)) {
                $translated[$french] = $values[$english];
            }
        }

        return $translated;
    }
}
