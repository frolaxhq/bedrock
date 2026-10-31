<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Http\Controllers\Administration;

use Inertia\Inertia;
use Inertia\Response;

class DashboardController
{
    public function __invoke(): Response
    {
        return Inertia::render('bedrock/Dashboard');
    }
}
