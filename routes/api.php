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

// Rutas protegidas
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [UsuarioController::class, 'logout']);

    // Rutas para el jugador
    Route::post('/game/create', [GameController::class, 'create']); // Crear partida
    Route::get('/game/available', [GameController::class, 'availableGames']); // Consultar partidas disponibles
    Route::post('/game/join', [GameController::class, 'join']); // Unirse a una partida
    Route::post('/game/guess', [GameController::class, 'guess']); // Enviar intento
    Route::post('/game/abandon', [GameController::class, 'abandon']); // Abandonar partida
    Route::get('/game/current', [GameController::class, 'current']); // Consultar partida actual
    Route::get('/game/history', [GameController::class, 'history']); // Historial de partidas

    // Rutas de administrador
    Route::get('/admin/games', [AdminController::class, 'index']); // Ver todas las partidas
    Route::post('/admin/activate', [AdminController::class, 'activateUser']); // Activar cuenta
    Route::post('/admin/deactivate', [AdminController::class, 'deactivate']); // Desactivar cuenta
    Route::post('/admin/promote', [AdminController::class, 'promoteToAdmin']); // Promover a administrador
});