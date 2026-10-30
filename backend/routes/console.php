<?php

use App\Models\EmailToken;
use Illuminate\Support\Facades\Schedule;

// Протухшие токены не нужны ни для чего: чистим раз в сутки.
Schedule::call(function () {
    EmailToken::query()
        ->where('expires_at', '<', now()->subDays(7))
        ->delete();
})->daily()->name('prune-email-tokens');
