@extends('bedrock::admin.layout')

@section('title', $installation->key)

@section('content')
    <a href="{{ route('bedrock.admin.modules.index') }}">← All modules</a>
    <h1>{{ $installation->key }}</h1>
    @include('bedrock::admin.modules.installation-status')
@endsection
