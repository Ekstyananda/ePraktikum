<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('portal:prune-previews')->hourly()->withoutOverlapping()->onOneServer();
// Scheduled daily backup (time/retention from settings) and manual runs queued from the UI.
Schedule::command('portal:backup --scheduled --queued')->everyMinute()->withoutOverlapping(120)->onOneServer()->runInBackground();
