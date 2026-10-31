<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Administration') · Bedrock</title>
    <style>
        :root { color-scheme: light; font-family: ui-sans-serif, system-ui, sans-serif; background: #f6f7f9; color: #182230; }
        * { box-sizing: border-box; }
        body { margin: 0; }
        a { color: #2554a4; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .topbar { background: #141c2b; color: white; padding: 1rem max(1.5rem, calc((100vw - 1100px) / 2)); display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
        .topbar a { color: #dbe7ff; }
        .brand { font-weight: 700; letter-spacing: -.02em; }
        .shell { width: min(1100px, calc(100% - 3rem)); margin: 2.5rem auto 5rem; }
        .eyebrow { text-transform: uppercase; letter-spacing: .1em; color: #607086; font-size: .75rem; font-weight: 700; }
        h1 { font-size: clamp(1.75rem, 3vw, 2.5rem); letter-spacing: -.04em; margin: .25rem 0 .5rem; }
        h2 { font-size: 1.15rem; margin: 0 0 1rem; }
        p { line-height: 1.5; }
        .muted { color: #59677a; }
        .panel { background: white; border: 1px solid #dfe4eb; border-radius: 12px; padding: 1.5rem; margin-top: 1.5rem; box-shadow: 0 3px 12px #18223008; }
        .notice { border-radius: 8px; padding: .8rem 1rem; margin-top: 1rem; background: #e9f6ec; color: #1c6335; }
        .notice.error { background: #fff0ef; color: #a12220; }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th, td { padding: .8rem .6rem; border-bottom: 1px solid #e8edf2; vertical-align: top; }
        th { font-size: .75rem; text-transform: uppercase; letter-spacing: .06em; color: #607086; }
        tr:last-child td { border-bottom: 0; }
        .table-wrap { overflow-x: auto; }
        .badge { display: inline-block; border-radius: 999px; padding: .2rem .6rem; background: #e9eef5; color: #35455a; font-size: .78rem; font-weight: 650; }
        .badge.enabled, .badge.passed, .badge.healthy { background: #dff4e6; color: #1c6335; }
        .badge.failed { background: #ffe7e5; color: #a12220; }
        .badge.warning { background: #fff1d0; color: #76520a; }
        .actions { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
        button { border: 1px solid #b7c5d8; background: white; border-radius: 7px; padding: .55rem .85rem; color: #223a5b; font: inherit; font-size: .88rem; font-weight: 650; cursor: pointer; }
        button.primary { background: #244b8e; border-color: #244b8e; color: white; }
        button:hover { filter: brightness(.94); }
        .empty { padding: 1.5rem 0; color: #59677a; }
        dl { display: grid; grid-template-columns: 10rem 1fr; gap: .65rem 1rem; margin: 0; }
        dt { color: #607086; }
        dd { margin: 0; overflow-wrap: anywhere; }
        @media (max-width: 640px) { .shell { width: calc(100% - 2rem); } .panel { padding: 1rem; } dl { grid-template-columns: 1fr; gap: .15rem; } dd { margin-bottom: .7rem; } }
    </style>
</head>
<body>
    <header class="topbar"><span class="brand">Bedrock / Administration</span><nav aria-label="Administration"><a href="{{ route('bedrock.admin.modules.index') }}">Modules</a> &nbsp; <a href="{{ route('bedrock.admin.roles.index') }}">Roles</a> &nbsp; <a href="{{ route('bedrock.admin.users.index') }}">Users</a>
        @foreach (app(\Frolax\Bedrock\Services\Administration\AdminPageRegistry::class)->forUser(auth()->user()) as $page)
            &nbsp; <a href="{{ route($page['route']) }}">{{ $page['label'] }}</a>
        @endforeach
    </nav></header>
    <main class="shell">
        @if (session('status'))
            <div class="notice" role="status">{{ session('status') }}</div>
        @endif
        @if ($errors->has('operation'))
            <div class="notice error" role="alert">{{ $errors->first('operation') }}</div>
        @endif
        @yield('content')
    </main>
</body>
</html>
