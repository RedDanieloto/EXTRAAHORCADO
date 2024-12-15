<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\AdminController;

// Rutas públicas
Route::post('/admin/register', [UsuarioController::class, 'registerAdmin']);
Route::post('/register', [UsuarioController::class, 'sendVerification']);
Route::post('/verify', [UsuarioController::class, 'verifyCode']);
Route::post('/login', [UsuarioController::class, 'login']);

// Rutas protegidas por Sanctum
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [UsuarioController::class, 'logout']);
    Route::post('/juego', [GameController::class, 'create']);
    Route::post('/game/guess', [GameController::class, 'guess']);
    Route::get('/game/current', [GameController::class, 'current']);
    Route::get('/game/history', [GameController::class, 'history']);
    Route::post('/game/leave', [GameController::class, 'abandon']);
    
    // Rutas de administrador
    Route::get('/admin/games', [AdminController::class, 'index']);
    Route::post('/admin/desactivate', [AdminController::class, 'deactivate']);
    Route::post('/admin/promote', [AdminController::class, 'promoteToAdmin']);
});