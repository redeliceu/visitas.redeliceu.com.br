<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// D-040: off until SELLFLUX_SYNC_ENABLED is set, after a --dry-run check.
Schedule::command('sellflux:sync-sellers')
    ->everyTenMinutes()
    ->withoutOverlapping(30)
    ->when(fn (): bool => (bool) config('services.sellflux.sync_enabled'));
