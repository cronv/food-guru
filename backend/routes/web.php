<?php

use Illuminate\Support\Facades\Route;

// Публичная часть и админка живут в Angular. Backend отдаёт только API.
Route::get('/', fn () => redirect(config('food_guru.site_url')));
