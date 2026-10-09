<?php

use App\Http\Controllers\Api\Runner\V1\BuildArtifactController;
use App\Http\Controllers\Api\Runner\V1\BuildClaimController;
use App\Http\Controllers\Api\Runner\V1\BuildControlController;
use App\Http\Controllers\Api\Runner\V1\BuildExecutionController;
use App\Http\Controllers\Api\Runner\V1\HeartbeatController;
use App\Http\Controllers\Api\V1\ArtifactController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BuildController;
use App\Http\Controllers\Api\V1\BuildLogController;
use App\Http\Controllers\Api\V1\BuildProfileController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ProjectBranchTriggerController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\RunnerController;
use App\Http\Controllers\Api\Webhooks\GithubWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Panel API (v1)
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function (): void {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        Route::get('dashboard', DashboardController::class);

        Route::get('projects', [ProjectController::class, 'index']);
        Route::post('projects', [ProjectController::class, 'store']);
        Route::get('projects/{project}', [ProjectController::class, 'show']);
        Route::put('projects/{project}', [ProjectController::class, 'update']);
        Route::delete('projects/{project}', [ProjectController::class, 'destroy']);
        Route::post('projects/{project}/activate', [ProjectController::class, 'activate']);
        Route::post('projects/{project}/deactivate', [ProjectController::class, 'deactivate']);
        Route::post('projects/{project}/webhook-secret', [ProjectController::class, 'rotateWebhookSecret']);
        Route::get('projects/{project}/webhook-deliveries', [ProjectController::class, 'webhookDeliveries']);

        Route::get('projects/{project}/branch-triggers', [ProjectBranchTriggerController::class, 'index']);
        Route::post('projects/{project}/branch-triggers', [ProjectBranchTriggerController::class, 'store']);
        Route::put('branch-triggers/{branchTrigger}', [ProjectBranchTriggerController::class, 'update']);
        Route::delete('branch-triggers/{branchTrigger}', [ProjectBranchTriggerController::class, 'destroy']);

        Route::get('projects/{project}/profiles', [BuildProfileController::class, 'index']);
        Route::post('projects/{project}/profiles', [BuildProfileController::class, 'store']);
        Route::put('profiles/{profile}', [BuildProfileController::class, 'update']);
        Route::delete('profiles/{profile}', [BuildProfileController::class, 'destroy']);
        Route::post('profiles/{profile}/activate', [BuildProfileController::class, 'activate']);
        Route::post('profiles/{profile}/deactivate', [BuildProfileController::class, 'deactivate']);

        Route::get('builds', [BuildController::class, 'index']);
        Route::post('projects/{project}/builds', [BuildController::class, 'store']);
        Route::get('builds/{build}', [BuildController::class, 'show']);
        Route::post('builds/{build}/cancel', [BuildController::class, 'cancel']);
        Route::get('builds/{build}/logs', [BuildLogController::class, 'index']);
        Route::get('builds/{build}/logs/stream', [BuildLogController::class, 'stream']);
        Route::get('builds/{build}/artifacts', [ArtifactController::class, 'index']);
        Route::get('artifacts/{artifact}/download', [ArtifactController::class, 'download']);

        Route::get('runners', [RunnerController::class, 'index']);
        Route::get('runners/{runner}', [RunnerController::class, 'show']);
    });
});

/*
|--------------------------------------------------------------------------
| GitHub webhooks (public, HMAC authenticated)
|--------------------------------------------------------------------------
*/

Route::post('webhooks/github/{projectSlug}', GithubWebhookController::class)
    ->middleware('throttle:webhooks')
    ->name('webhooks.github');

/*
|--------------------------------------------------------------------------
| Runner API (v1) - outbound polling only, bearer token authenticated
|--------------------------------------------------------------------------
*/

Route::prefix('runner/v1')->middleware(['auth:sanctum', 'runner.active', 'throttle:runner'])->group(function (): void {
    Route::post('heartbeat', [HeartbeatController::class, 'store']);

    Route::post('builds/claim', [BuildClaimController::class, 'store']);
    Route::get('builds/{build}', [BuildClaimController::class, 'show']);

    Route::post('builds/{build}/start', [BuildExecutionController::class, 'start']);
    Route::post('builds/{build}/logs', [BuildExecutionController::class, 'logs']);
    Route::post('builds/{build}/heartbeat', [BuildExecutionController::class, 'heartbeat']);
    Route::post('builds/{build}/complete', [BuildExecutionController::class, 'complete']);
    Route::get('builds/{build}/control', [BuildControlController::class, 'show']);

    Route::post('builds/{build}/artifacts', [BuildArtifactController::class, 'store']);
});
