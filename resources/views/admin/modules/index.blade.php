@extends('bedrock::admin.layout')

@section('title', 'Modules')

@section('content')
    <div class="eyebrow">Control plane</div>
    <h1>Modules</h1>
    <p class="muted">Review installed capabilities, inspect health, and manage their lifecycle.</p>

    @if ($installations->isNotEmpty())
        <section class="panel" aria-labelledby="installations-title">
            <h2 id="installations-title">Installations</h2>
            <p class="muted">Refresh this page to see progress. Failed installations are not retried automatically.</p>
            <div class="table-wrap"><table>
                <thead><tr><th scope="col">Module</th><th scope="col">Operation</th><th scope="col">Status</th><th scope="col">Details</th></tr></thead>
                <tbody>
                    @foreach ($installations as $installation)
                        <tr>
                            <td><a href="{{ route('bedrock.admin.modules.show', $installation->key) }}">{{ $installation->key }}</a></td>
                            <td>{{ $installation->id }}</td>
                            <td><span class="badge {{ $installation->status }}">{{ $installation->status }}</span></td>
                            <td>{{ $installation->error ?? ($installation->isActive() ? 'Waiting for the installation worker.' : 'Installation finished.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        </section>
    @endif

    <section class="panel" aria-labelledby="registered-title">
        <h2 id="registered-title">Registered modules</h2>
        @if ($modules->isEmpty())
            <p class="empty">No modules are registered yet.</p>
        @else
            <div class="table-wrap"><table>
                <thead><tr><th scope="col">Module</th><th scope="col">Version</th><th scope="col">Status</th><th scope="col">Health</th><th scope="col">Action</th></tr></thead>
                <tbody>
                    @foreach ($modules as $module)
                        <tr>
                            <td><a href="{{ route('bedrock.admin.modules.show', $module->key) }}">{{ $module->name }}</a><br><span class="muted">{{ $module->key }}</span></td>
                            <td>{{ $module->version }}</td>
                            <td><span class="badge {{ $module->status->value }}">{{ $module->status->value }}</span></td>
                            <td><span class="badge {{ $module->health->value }}">{{ $module->health->value }}</span></td>
                            <td><a href="{{ route('bedrock.admin.modules.show', $module->key) }}">Inspect</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </section>

    @if ($unregistered->isNotEmpty())
        <section class="panel" aria-labelledby="available-title">
            <h2 id="available-title">Available on disk</h2>
            <p class="muted">These modules are present in the configured module directory but have not been installed.</p>
            <div class="table-wrap"><table>
                <thead><tr><th scope="col">Module</th><th scope="col">Version</th><th scope="col">Action</th></tr></thead>
                <tbody>
                    @foreach ($unregistered as $module)
                        <tr>
                            <td>{{ $module->manifest->name }}<br><span class="muted">{{ $module->key }}</span></td>
                            <td>{{ $module->manifest->version }}</td>
                            <td>
                                @if ($activeInstallations->contains($module->key))
                                    <a href="{{ route('bedrock.admin.modules.show', $module->key) }}">View installation</a>
                                @else
                                    <form method="post" action="{{ route('bedrock.admin.modules.install', $module->key) }}">@csrf<button class="primary" type="submit">Queue installation</button></form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        </section>
    @endif

    @if ($discoveryFailures !== [])
        <section class="panel" aria-labelledby="invalid-title">
            <h2 id="invalid-title">Discovery failures</h2>
            @foreach ($discoveryFailures as $key => $reason)
                <p><strong>{{ $key }}</strong>: {{ $reason }}</p>
            @endforeach
        </section>
    @endif
@endsection
