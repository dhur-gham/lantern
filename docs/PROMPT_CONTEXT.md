# Agent context (shared)

PHP env, run in every PowerShell call:
  $sp="C:\Users\pc\AppData\Local\Temp\claude\C--Users-pc-Desktop-TodoApi\f31e6bae-4e36-45b3-89ec-3298df5f085e\scratchpad"; $env:PHPRC="$sp\phpini"; $env:PATH="$env:USERPROFILE\.config\herd\bin\php84;$env:PATH"

Tests: `php vendor/bin/phpunit` in lantern; `php artisan test` in demo-app. Demo runs in-process, never `artisan serve`.
Style: strict_types, final classes, sparse comments, match neighbouring code.
Rules: no commits/pushes by agents; no Claude/Anthropic mention in code, comments or commits.
Plan: docs/PLAN.md. Verified framework facts: docs/research/04-verified.md.
