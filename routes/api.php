<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/ai')->middleware('api.token')->group(function (): void {
    Route::post('/ask', [AiController::class, 'ask']);
    Route::post('/ask-async', [AiController::class, 'askAsync']);
    Route::post('/translate', [AiController::class, 'translate']);
    Route::get('/status/{taskId}', [AiController::class, 'status']);
});
