<?php

return ['upload_max_kb' => min(10240, max(1, (int) env('ACADEMIC_UPLOAD_MAX_KB', 10240)))];
