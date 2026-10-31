@extends('bedrock::admin.layout')

@section('title', $module->name)

@section('content')
    <a href="{{ route('bedrock.admin.modules.index') }}">← All modules</a>
    <div class="eyebrow" style="margin-top: 1.5rem">{{ $module->key }}</div>
    <h1>{{ $module->name }}</h1>
    <p class="muted">Version {{ $module->version }} · <span class="badge {{ $module->status->value }}">{{ $module->status->value }}</span> <span class="badge {{ $module->health->value }}">{{ $module->health->value }}</span></p>

    @include('bedrock::admin.modules.installation-status')

    <section class="panel" aria-labelledby="lifecycle-title">
        <h2 id="lifecycle-title">Lifecycle</h2>
        <div class="actions">
            @if ($module->status->value === 'installed' || $module->status->value === 'disabled')
                <form method="post" action="{{ route('bedrock.admin.modules.enable', $module->key) }}">@csrf<button class="primary" type="submit">Enable module</button></form>
            @elseif ($module->status->value === 'enabled')
                <form method="post" action="{{ route('bedrock.admin.modules.disable', $module->key) }}">@csrf<button type="submit">Disable module</button></form>
            @endif
        </div>
        @if ($module->failure_reason)
            <p class="notice error" role="alert">{{ $module->failure_reason }}</p>
        @endif
    </section>

    <section class="panel" aria-labelledby="details-title">
        <h2 id="details-title">Details</h2>
        <dl>
            <dt>Provider</dt><dd>{{ $module->provider }}</dd>
            <dt>Installed</dt><dd>{{ $module->installed_at?->format('Y-m-d H:i') ?? 'Not installed' }}</dd>
            <dt>Enabled</dt><dd>{{ $module->enabled_at?->format('Y-m-d H:i') ?? 'Not enabled' }}</dd>
            <dt>Failure code</dt><dd>{{ $module->failure_code?->value ?? 'None' }}</dd>
        </dl>
    </section>

    <section class="panel" aria-labelledby="settings-title">
        <h2 id="settings-title">Settings</h2>
        <p class="muted">Edit the settings declared by this module.</p>
        <a href="{{ route('bedrock.admin.settings.show', $module->key) }}">Manage settings →</a>
    </section>

    <section class="panel" aria-labelledby="health-title">
        <h2 id="health-title">Health checks <span class="badge {{ $report->ok ? 'passed' : 'failed' }}">{{ $report->ok ? 'passed' : 'attention needed' }}</span></h2>
        <div class="table-wrap"><table>
            <thead><tr><th scope="col">Check</th><th scope="col">Status</th><th scope="col">Details</th></tr></thead>
            <tbody>
                @foreach ($report->checks as $check)
                    <tr><td>{{ $check->code }}</td><td><span class="badge {{ $check->status->value }}">{{ $check->status->value }}</span></td><td>{{ $check->message }}</td></tr>
                @endforeach
            </tbody>
        </table></div>
    </section>
@endsection
