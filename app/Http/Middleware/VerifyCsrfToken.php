<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * URI, які мають бути виключені з CSRF перевірки.
     *
     * @var array<int, string>
     */
    protected $except = [
        '/telegram/webhook',
    ];
}
