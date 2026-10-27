<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Lantern</title>
<style>{!! \Lantern\Lantern::asset('lantern.css') !!}</style>
</head>
<body data-base="{{ $lantern_base ?? '' }}" data-page="@yield('page')" data-id="@yield('id')">
<header class="top">
<a class="brand" href="{{ $lantern_base ?? '' }}/"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 3h6M10 3v2m4-2v2"/><path d="M8 7h8l1 2v8l-1 2H8l-1-2V9z"/><path d="M12 10c1.4 1.3 2 2.2 2 3.2a2 2 0 0 1-4 0c0-1 .6-1.9 2-3.2z" fill="currentColor"/><path d="M9 21h6"/></svg>Lantern</a>
<nav class="nav" aria-label="Sections">
<a href="{{ $lantern_base ?? '' }}/" @if (trim($__env->yieldContent('nav')) === 'traces') aria-current="page" @endif>Traces</a>
<a href="{{ $lantern_base ?? '' }}/issues" @if (trim($__env->yieldContent('nav')) === 'issues') aria-current="page" @endif>Issues</a>
</nav>
</header>
<main class="wrap" id="app"><div class="state">Loading</div><noscript><div class="state err">Lantern needs JavaScript to draw this page.</div></noscript></main>
<script>{!! \Lantern\Lantern::asset('lantern.js') !!}</script>
</body>
</html>
