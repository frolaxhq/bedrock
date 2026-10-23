<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdministrationEnabled
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('bedrock.administration.enabled', false), 404);

        return $next($request);
    }
}
