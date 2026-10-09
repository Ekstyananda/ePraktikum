<?php

return [
    // Scheduled backups run only when enabled; manual runs also need a writable destination.
    'enabled' => (bool) env('BACKUP_ENABLED', false),
    // Directory inside the container, mounted from a host path separate from the portal-storage volume.
    'destination' => env('BACKUP_DESTINATION', '/backups'),
    // Shown in the UI instead of the raw path.
    'label' => env('BACKUP_DESTINATION_LABEL', 'Disk backup lokal'),
    // Optional: encrypts archive entries with AES-256 (required when the destination is remote).
    'password' => env('BACKUP_PASSWORD'),
    // Defaults; admin can change time/retention in the UI (settings table).
    'time' => env('BACKUP_TIME', '02:00'),
    'retention' => (int) env('BACKUP_RETENTION', 7),
    // Change-triggered runs: queue a backup once this many audited changes happened since the last successful one
    // (0 = off), but never sooner than the minimum interval (minutes) after the previous run.
    'change_threshold' => max(0, (int) env('BACKUP_CHANGE_THRESHOLD', 0)),
    'change_min_interval' => max(5, (int) env('BACKUP_CHANGE_MIN_INTERVAL', 60)),
    // Data of these tables is volatile or session-bound; only their structure is dumped.
    'structure_only' => ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens', 'import_previews'],
];
