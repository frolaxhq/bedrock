@if ($installation)
    <section class="panel" aria-labelledby="installation-title">
        <h2 id="installation-title">Installation <span class="badge {{ $installation->status }}">{{ $installation->status }}</span></h2>
        <dl>
            <dt>Operation</dt><dd>{{ $installation->id }}</dd>
            <dt>Queued</dt><dd>{{ $installation->created_at->format('Y-m-d H:i:s') }}</dd>
            <dt>Started</dt><dd>{{ $installation->started_at?->format('Y-m-d H:i:s') ?? 'Waiting for worker' }}</dd>
            <dt>Finished</dt><dd>{{ $installation->finished_at?->format('Y-m-d H:i:s') ?? 'Pending' }}</dd>
        </dl>
        @if ($installation->error)
            <p class="notice error" role="alert">{{ $installation->error }}</p>
        @elseif ($installation->isActive())
            <p class="muted">Refresh this page to see progress.</p>
        @endif
        <p class="muted">Failed installations are not retried automatically.</p>
    </section>
@endif
