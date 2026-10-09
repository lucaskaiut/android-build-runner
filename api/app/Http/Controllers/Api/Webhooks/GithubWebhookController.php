<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\GithubWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GithubWebhookController extends Controller
{
    public function __invoke(Request $request, string $projectSlug, GithubWebhookService $webhooks): JsonResponse
    {
        $result = $webhooks->handle($projectSlug, $request);

        return response()->json($result['body'], $result['status']);
    }
}
