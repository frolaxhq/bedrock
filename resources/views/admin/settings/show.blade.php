@extends('bedrock::admin.layout')

@section('title', $module->name . ' settings')

@section('content')
    <a href="{{ route('bedrock.admin.modules.show', $module->key) }}">← {{ $module->name }}</a>
    <div class="eyebrow" style="margin-top:1.5rem">{{ $module->key }}</div>
    <h1>Module settings</h1>
    <p class="muted">Only settings declared by this module can be edited here. Secret values are never displayed.</p>

    @forelse ($definitions as $definition)
        @php
            $value = $publicSettings['values'][$definition->key] ?? $definition->default;
            $configured = collect($publicSettings['definitions'])->firstWhere('key', $definition->key)['configured'] ?? false;
        @endphp
        <section class="panel" aria-labelledby="setting-{{ $loop->index }}">
            <h2 id="setting-{{ $loop->index }}">{{ $definition->label }}</h2>
            <p class="muted">{{ $definition->key }}@if ($definition->description) · {{ $definition->description }} @endif</p>
            @if ($definition->isSecret() && $configured)
                <p class="badge passed">Secret configured</p>
            @endif
            <form method="post" action="{{ route('bedrock.admin.settings.update', [$module->key, $definition->key]) }}">
                @csrf
                @if ($definition->type === 'boolean')
                    <label><input type="checkbox" name="value" value="1" @checked((bool) $value)> Enabled</label>
                @elseif ($definition->type === 'textarea')
                    <label for="value-{{ $loop->index }}">Value</label><br>
                    <textarea id="value-{{ $loop->index }}" name="value" rows="4" style="width:min(100%,40rem)">{{ $value }}</textarea>
                @elseif ($definition->type === 'select' || $definition->type === 'multiselect')
                    <label for="value-{{ $loop->index }}">Value</label><br>
                    <select id="value-{{ $loop->index }}" name="{{ $definition->type === 'multiselect' ? 'value[]' : 'value' }}" @if ($definition->type === 'multiselect') multiple size="{{ min(8, max(2, count($definition->options))) }}" @endif style="min-width:15rem;padding:.5rem">
                        @foreach ($definition->options as $optionValue => $optionLabel)
                            <option value="{{ $optionValue }}" @selected(is_array($value) ? in_array($optionValue, $value, true) : (string) $value === (string) $optionValue)>{{ $optionLabel }}</option>
                        @endforeach
                    </select>
                @else
                    <label for="value-{{ $loop->index }}">Value</label><br>
                    <input id="value-{{ $loop->index }}" name="value" type="{{ $definition->isSecret() ? 'password' : ($definition->type === 'number' ? 'number' : 'text') }}" @if ($definition->type === 'number') step="any" @endif value="{{ $definition->isSecret() ? '' : $value }}" style="width:min(100%,30rem);padding:.55rem;border:1px solid #b7c5d8;border-radius:7px">
                @endif
                <div class="actions" style="margin-top:1rem"><button class="primary" type="submit">Save</button></div>
            </form>
            <form method="post" action="{{ route('bedrock.admin.settings.reset', [$module->key, $definition->key]) }}" style="margin-top:.75rem">
                @csrf
                <button type="submit">Reset to default</button>
            </form>
        </section>
    @empty
        <section class="panel"><p class="empty">This module declares no settings.</p></section>
    @endforelse
@endsection
