@extends('bedrock::admin.layout')

@section('title', 'Users')

@section('content')
    <div class="eyebrow">Access control</div>
    <h1>Users</h1>
    <p class="muted">Assign Bedrock roles to users from the configured auth provider. The final administrator cannot be removed.</p>

    @foreach ($users as $user)
        <section class="panel" aria-labelledby="user-{{ $user->getKey() }}">
            <h2 id="user-{{ $user->getKey() }}">{{ $user->getAttribute('name') ?: $user->getKey() }}</h2>
            <p class="muted">{{ $user->getAttribute('email') }}</p>
            <form method="post" action="{{ route('bedrock.admin.users.roles', $user->getKey()) }}">
                @csrf
                @foreach ($roles as $role)
                    <label style="display:block;margin:.65rem 0">
                        <input type="checkbox" name="roles[]" value="{{ $role->name }}" @checked(method_exists($user, 'hasRole') && $user->hasRole($role->name))>
                        {{ $role->name }}
                    </label>
                @endforeach
                <button class="primary" type="submit">Save roles</button>
            </form>
        </section>
    @endforeach

    <div style="margin-top:1.5rem">{{ $users->links() }}</div>
@endsection
