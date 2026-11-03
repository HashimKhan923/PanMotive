@extends('layouts.storefront')

@section('title', $page->title.' — '.config('app.name'))
@section('meta_description', $page->meta_description ?: $page->title)

@section('content')
<article class="container-x max-w-3xl py-10 lg:py-14">
    <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $page->title }}</h1>
    <p class="mt-1 text-xs text-muted">Last updated {{ $page->updated_at->format('F j, Y') }}</p>
    <x-ui.rich-text :content="$page->body" class="mt-6" />
</article>
@endsection
