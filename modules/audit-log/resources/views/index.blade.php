@extends('bedrock::admin.layout')

@section('title', 'Audit history')

@section('content')
    <div class="eyebrow">Operations</div>
    <h1>Audit history</h1>
    <p class="muted">Recorded module, settings, and access changes. Secret values are excluded.</p>
    <section class="panel">
        <form method="get" class="actions" aria-label="Filter audit history">
            <label>Event <input name="event" value="{{ $filters['event'] ?? '' }}" maxlength="128"></label>
            <label>Module <input name="module" value="{{ $filters['module'] ?? '' }}" maxlength="255"></label>
            <label>Actor ID <input name="actor" value="{{ $filters['actor'] ?? '' }}" maxlength="255"></label>
            <label>From <input name="from" type="date" value="{{ $filters['from'] ?? '' }}"></label>
            <label>To <input name="to" type="date" value="{{ $filters['to'] ?? '' }}"></label>
            <button type="submit">Filter</button>
        </form>
    </section>
    <section class="panel table-wrap">
        <table><thead><tr><th>Time</th><th>Event</th><th>Actor</th><th>Subject</th><th>Details</th></tr></thead><tbody>
            @forelse ($entries as $entry)
                <tr>
                    <td>{{ $entry->occurred_at->format('Y-m-d H:i:s') }}</td>
                    <td>{{ $entry->event }}</td>
                    <td>{{ $entry->actor_id ?? 'System' }}</td>
                    <td>{{ $entry->subject_type }} {{ $entry->subject_id }}</td>
                    <td><code>{{ json_encode($entry->metadata, JSON_UNESCAPED_SLASHES) }}</code></td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">No audit entries match these filters.</td></tr>
            @endforelse
        </tbody></table>
        {{ $entries->links() }}
    </section>
@endsection
