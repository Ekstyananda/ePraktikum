<?php

// Public portal limits per minute. Campus labs share one public IP, so per-IP allowances are generous;
// the per-identity (NBI/token) limit stays strict to stop guessing and spam.
$limit = fn (string $key, int $default) => max(1, (int) env($key, $default));

return ['rate_limits' => [
    'submit_per_ip' => $limit('PORTAL_SUBMIT_PER_IP', 300),
    'submit_per_identity' => $limit('PORTAL_SUBMIT_PER_IDENTITY', 6),
    'status_per_ip' => $limit('PORTAL_STATUS_PER_IP', 120),
    'download_per_ip' => $limit('PORTAL_DOWNLOAD_PER_IP', 300),
]];
