<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('kontotrack:backup')->dailyAt('02:00')->withoutOverlapping()->onOneServer();
// Proves every week that the newest backup can actually be restored; it works on a scratch database only.
Schedule::command('kontotrack:backup:restore --drill')->weeklyOn(0, '03:00')->withoutOverlapping()->onOneServer();
// Morning reminders by email: deadlines, document requests and approvals waiting for each user.
Schedule::command('kontotrack:reminders:send')->dailyAt('07:00')->timezone(config('kontotrack.display_timezone'))->withoutOverlapping()->onOneServer();
