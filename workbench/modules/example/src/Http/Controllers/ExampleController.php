<?php

declare(strict_types=1);

namespace Modules\Example\Http\Controllers;

use Frolax\Bedrock\Facades\Frontend;
use Inertia\Inertia;
use Inertia\Response;

class ExampleController
{
    public function __invoke(): Response
    {
        return Inertia::render(Frontend::page('example', 'Index'), [
            'message' => 'Hello from an independently built Bedrock module.',
        ]);
    }
}
