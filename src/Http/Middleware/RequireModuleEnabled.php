<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Http\Middleware;

use Closure;
use Frolax\Bedrock\Contracts\Module\ModuleRepository;
use Frolax\Bedrock\Services\Module\CompiledModuleBoot;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireModuleEnabled
{
    public function __construct(protected ModuleRepository $modules) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string $key): Response
    {
        $snapshot = app(CompiledModuleBoot::class)->read();

        if ($snapshot !== null) {
            $enabled = false;
            foreach ($snapshot['modules'] as $entry) {
                if ($entry['attributes']['key'] === $key) {
                    $enabled = true;

                    break;
                }
            }
            abort_unless($enabled, 404);
        } else {
            abort_unless($this->modules->find($key)?->isEnabled() === true, 404);
        }

        return $next($request);
    }
}
