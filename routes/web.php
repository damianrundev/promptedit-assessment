<?php

use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\PixelforgeWebhookController;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::middleware('auth')->group(function () {
    Route::redirect('/dashboard', '/generate')->name('dashboard');
    Route::get('/generate', [GenerationController::class, 'create'])->name('generations.create');
    Route::post('/generate/estimate', [GenerationController::class, 'estimate'])->name('generations.estimate');
    Route::post('/generate', [GenerationController::class, 'store'])->name('generations.store');
    Route::get('/history', [GenerationController::class, 'index'])->name('generations.index');
    Route::get('/generations/{generation}/image', [GenerationController::class, 'image'])->name('generations.image');
    Route::post('/credits', [CreditController::class, 'store'])->name('credits.store');
});

Route::middleware(['auth', 'admin'])->get('/admin/report', [ReportController::class, 'index'])->name('admin.report');

// Server-to-server: no session, no CSRF (excluded in bootstrap/app.php), authenticated by HMAC signature.
Route::post('/webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
