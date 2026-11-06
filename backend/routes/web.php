<?php

use App\Exceptions\ApiProblem;
use App\Http\Controllers\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::post('api/v1/broadcasting/auth', function (Request $request) {
    $request->validate(['socket_id' => 'required|string|max:100|regex:/^[0-9]+\.[0-9]+$/D', 'channel_name' => 'required|string|max:200']);

    return Broadcast::auth($request);
})->middleware(['auth', 'active', 'verified', 'throttle:api']);

Route::prefix('api/v1/auth')->middleware('throttle:auth')->group(function () {
    Route::get('email/notice', fn () => throw new ApiProblem(403, 'EMAIL_NOT_VERIFIED', 'Confirme seu e-mail.'))->name('verification.notice');
    Route::post('register', [AuthController::class, 'register'])->middleware('guest');
    Route::post('login', [AuthController::class, 'login'])->middleware('guest')->name('login');
    Route::post('forgot-password', [AuthController::class, 'forgot']);
    Route::post('reset-password', [AuthController::class, 'reset'])->name('password.reset');
    Route::middleware(['auth', 'active'])->group(function () {
        Route::get('me', fn (Request $r) => ['data' => $r->user()]);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('email/verification-notification', [AuthController::class, 'resend'])->name('verification.send');
        Route::get('email/verify/{id}/{hash}', [AuthController::class, 'verify'])->middleware('signed')->name('verification.verify');
    });
});
