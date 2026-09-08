<?php

namespace App\Shared\Http\Controllers;

use App\Shared\Http\Responses\ApiResponse;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

/**
 * Every module controller extends this instead of the framework's base
 * Controller, so authorization, validation and the response envelope
 * behave identically in every module.
 */
abstract class ApiController extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    protected function success(mixed $data = null, ?string $message = null, array $meta = [], int $status = 200)
    {
        return ApiResponse::success($data, $message, $meta, $status);
    }

    protected function error(string $message, array $errors = [], int $status = 400, ?string $code = null)
    {
        return ApiResponse::error($message, $errors, $status, $code);
    }
}
