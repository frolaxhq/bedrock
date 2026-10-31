<?php

declare(strict_types=1);

// Run only inside a disposable Laravel consumer before its frontend build.
$root = getcwd();
$composer = json_decode(file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

if (($composer['name'] ?? null) !== 'laravel/laravel') {
    throw new RuntimeException('Run this fixture from a disposable Laravel consumer.');
}
$directory = $root.'/resources/js/pages';

if (! is_dir($directory)) {
    mkdir($directory, 0755, true);
}
file_put_contents($directory.'/ProjectExample.tsx', <<<'TSX'
import { AppLayout } from '@frolax/bedrock/ui';
export default function ProjectExample() {
    return <AppLayout title="Project example"><div className="p-6"><h1 className="text-2xl font-semibold">Your business feature</h1><p className="mt-3 text-muted-foreground">This page belongs to the Laravel application and uses Bedrock’s shared layout.</p></div></AppLayout>;
}
TSX);
$routes = $root.'/routes/web.php';

if (! str_contains(file_get_contents($routes), 'foundation.project-example')) {
    file_put_contents($routes, <<<'PHP_ROUTE'

\Illuminate\Support\Facades\Route::middleware(['auth:web', 'can:bedrock.admin', \Frolax\Bedrock\Http\Middleware\HandleInertiaRequests::class])
    ->get('/project-example', fn () => \Inertia\Inertia::render('ProjectExample'))
    ->name('foundation.project-example');
PHP_ROUTE, FILE_APPEND);
}
