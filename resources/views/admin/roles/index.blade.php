@extends('bedrock::admin.layout')

@section('title', 'Roles')

@section('content')
    <div class="eyebrow">Access control</div>
    <h1>Roles</h1>
    <p class="muted">Grant active Bedrock permissions to application roles. The administrator role cannot lose administration access.</p>

    <section class="panel" aria-labelledby="create-title">
        <h2 id="create-title">Create role</h2>
        <form method="post" action="{{ route('bedrock.admin.roles.store') }}" class="actions">
            @csrf
            <label for="role-name">Name</label>
            <input id="role-name" name="name" required maxlength="100" pattern="[a-z][a-z0-9_.-]*" value="{{ old('name') }}" style="padding:.55rem;border:1px solid #b7c5d8;border-radius:7px">
            <button class="primary" type="submit">Create role</button>
        </form>
        @error('name') <p class="notice error" role="alert">{{ $message }}</p> @enderror
    </section>

    @foreach ($roles as $role)
        <section class="panel" aria-labelledby="role-{{ $role->getKey() }}">
            <h2 id="role-{{ $role->getKey() }}">{{ $role->name }}</h2>
            <p class="muted">Guard: {{ $role->guard_name }}</p>
            <form method="post" action="{{ route('bedrock.admin.roles.permissions', $role->getKey()) }}">
                @csrf
                @forelse ($permissions as $permission)
                    <label style="display:block;margin:.65rem 0">
                        <input type="checkbox" name="permissions[]" value="{{ $permission->name }}" @checked($role->permissions->contains('name', $permission->name))>
                        {{ $permission->name }}
                    </label>
                @empty
                    <p class="muted">No active Bedrock permissions are available.</p>
                @endforelse
                <button class="primary" type="submit">Save permissions</button>
            </form>
        </section>
    @endforeach
@endsection
