<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="ltr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        @viteReactRefresh
        @vite(['resources/css/bedrock.css', 'resources/js/bedrock.tsx'])
        @inertiaHead
    </head>
    <body class="min-h-screen bg-background text-foreground antialiased">
        @inertia
    </body>
</html>
