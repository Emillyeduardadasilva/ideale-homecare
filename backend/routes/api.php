<?php

use App\Http\Controllers\Api\V1\CalendarController;
use App\Http\Controllers\Api\V1\CareNetworkController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\DailyViewController;
use App\Http\Controllers\Api\V1\DiaryController;
use App\Http\Controllers\Api\V1\ElderController;
use App\Http\Controllers\Api\V1\MedicationController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OpenApiController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\VisitController;
use App\Http\Middleware\IdempotentRequest;
use Illuminate\Support\Facades\Route;

Route::get('v1/openapi.json', OpenApiController::class);

foreach (['membership', 'invitation', 'task', 'medication', 'occurrence', 'visit', 'event', 'contact', 'shift'] as $parameter) {
    Route::pattern($parameter, '[0-9]+');
}
Route::prefix('v1')->middleware(['auth:sanctum', 'active', 'verified', IdempotentRequest::class])->group(function () {
    Route::get('elders', [ElderController::class, 'index']);
    Route::post('elders', [ElderController::class, 'store']);
    Route::post('invitations/accept', [CareNetworkController::class, 'accept']);
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->whereNumber('notification');
    Route::get('push/public-key', [NotificationController::class, 'key']);
    Route::post('push/subscriptions', [NotificationController::class, 'subscribe']);
    Route::delete('push/subscriptions/{subscription}', [NotificationController::class, 'unsubscribe'])->whereNumber('subscription');
    Route::prefix('elders/{elder}')->whereNumber('elder')->group(function () {
        Route::get('/', [ElderController::class, 'show']);
        Route::put('/', [ElderController::class, 'update']);
        Route::get('memberships', [CareNetworkController::class, 'index']);
        Route::post('invitations', [CareNetworkController::class, 'invite']);
        Route::get('invitations', [CareNetworkController::class, 'invitations']);
        Route::delete('invitations/{invitation}', [CareNetworkController::class, 'revokeInvitation']);
        Route::delete('memberships/{membership}', [CareNetworkController::class, 'revoke']);
        Route::delete('elder-account', [CareNetworkController::class, 'unlinkElderAccount']);
        Route::get('tasks', [TaskController::class, 'index']);
        Route::post('tasks', [TaskController::class, 'store']);
        Route::put('tasks/{task}', [TaskController::class, 'update']);
        Route::post('tasks/{task}/complete', [TaskController::class, 'complete']);
        Route::post('tasks/{task}/cancel', [TaskController::class, 'cancel']);
        Route::get('medications', [MedicationController::class, 'index']);
        Route::post('medications', [MedicationController::class, 'store']);
        Route::put('medications/{medication}', [MedicationController::class, 'update']);
        Route::get('medication-occurrences', [MedicationController::class, 'occurrences']);
        Route::post('medication-occurrences/{occurrence}/confirm', [MedicationController::class, 'confirm']);
        Route::get('observations', [DiaryController::class, 'index']);
        Route::post('observations', [DiaryController::class, 'store']);
        Route::get('measurements', [DiaryController::class, 'measurements']);
        Route::post('measurements', [DiaryController::class, 'measure']);
        Route::get('professionals', [VisitController::class, 'professionals']);
        Route::get('visits', [VisitController::class, 'index']);
        Route::post('visits', [VisitController::class, 'store']);
        Route::put('visits/{visit}', [VisitController::class, 'update']);
        Route::post('visits/{visit}/status', [VisitController::class, 'status']);
        Route::get('visits/{visit}/briefing', [VisitController::class, 'briefing']);
        Route::post('visits/{visit}/observation-reads', [VisitController::class, 'read']);
        Route::get('calendar', [CalendarController::class, 'index']);
        Route::get('daily-view', DailyViewController::class);
        Route::post('family-events', [CalendarController::class, 'store']);
        Route::put('family-events/{event}', [CalendarController::class, 'update']);
        Route::get('contacts', [ContactController::class, 'index']);
        Route::post('contacts', [ContactController::class, 'store']);
        Route::put('contacts/{contact}', [ContactController::class, 'update']);
        Route::get('care-shifts', [ContactController::class, 'shifts']);
        Route::post('care-shifts', [ContactController::class, 'shift']);
        Route::put('care-shifts/{shift}', [ContactController::class, 'updateShift']);
        Route::delete('care-shifts/{shift}', [ContactController::class, 'cancelShift']);
        Route::get('quick-call', [ContactController::class, 'current']);
    });
});
