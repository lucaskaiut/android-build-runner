<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Build lease
    |--------------------------------------------------------------------------
    |
    | How long a build stays assigned to a runner without any authenticated
    | call (logs, heartbeat or control). When the lease expires the build is
    | reconciled as failed - it is never silently requeued, because the API
    | cannot prove the previous process stopped.
    |
    */
    'lease_seconds' => (int) env('BUILD_LEASE_SECONDS', 180),

    /*
    | Runner considered offline after this many seconds without a heartbeat.
    */
    'runner_offline_seconds' => (int) env('RUNNER_OFFLINE_SECONDS', 120),

    /*
    |--------------------------------------------------------------------------
    | Logs
    |--------------------------------------------------------------------------
    */
    'log_max_lines_per_build' => (int) env('BUILD_LOG_MAX_LINES', 100000),
    'log_max_message_bytes' => (int) env('BUILD_LOG_MAX_MESSAGE_BYTES', 8192),
    'log_max_batch_events' => (int) env('BUILD_LOG_MAX_BATCH_EVENTS', 500),
    'log_retention_days' => (int) env('BUILD_LOG_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Artifacts
    |--------------------------------------------------------------------------
    */
    'artifact_max_megabytes' => (int) env('BUILD_ARTIFACT_MAX_MB', 512),
    'artifact_retention_days' => (int) env('BUILD_ARTIFACT_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Execution
    |--------------------------------------------------------------------------
    */
    'default_timeout_seconds' => (int) env('BUILD_DEFAULT_TIMEOUT', 3600),
    'max_timeout_seconds' => (int) env('BUILD_MAX_TIMEOUT', 21600),
    'build_record_retention_days' => (int) env('BUILD_RECORD_RETENTION_DAYS', 180),

    /*
    |--------------------------------------------------------------------------
    | Store submit
    |--------------------------------------------------------------------------
    |
    | The API scheduler uploads successful AABs to the store. This timeout
    | applies to the Google Play upload/commit request.
    |
    */
    'submit_timeout_seconds' => (int) env('BUILD_SUBMIT_TIMEOUT', 900),
];
