<?php

declare(strict_types=1);

namespace Lantern\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lantern\Storage\TraceQueries;

final class ApiController
{
    public function __construct(private readonly TraceQueries $queries)
    {
    }

    public function traces(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->queries->traces(
            $request->only(['status', 'min_ms', 'has_issues', 'path']),
            $request->integer('limit', 50),
        )]);
    }

    public function trace(string $id): JsonResponse
    {
        $trace = $this->queries->trace($id);
        abort_if($trace === null, 404);

        return response()->json(['data' => $trace]);
    }

    public function issues(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->queries->issues(
            $request->only(['type', 'severity', 'sort', 'resolved']),
            $request->integer('limit', 50),
        )]);
    }

    public function issue(string $key): JsonResponse
    {
        $issue = $this->queries->issue($key);
        abort_if($issue === null, 404);

        return response()->json(['data' => $issue]);
    }
}
