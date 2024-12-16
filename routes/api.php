<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\UsuarioController;
use Illuminate\Support\Facades\Route;

// Rutas públicas
Route::post('/admin/register', [UsuarioController::class, 'registerAdmin']); // Registrar administrador
Route::post('/register', [UsuarioController::class, 'sendVerification']);    // Registrar usuario
Route::post('/verify', [UsuarioController::class, 'verifyCode']);            // Verificar código
Route::post('/login', [UsuarioController::class, 'login']);                  // Iniciar sesión
Route::post('/reesend', [UsuarioController::class, 'resendVerificationCode']);            // Reenviar 

// Rutas protegidas
Route::middleware('auth:sanctum')->group(function () {
    // Usuario
    Route::post('/logout', [UsuarioController::class, 'logout']);            // Cerrar sesión

    // Partidas
    Route::post('/game', [GameController::class, 'create']);                 // Crear una partida
    Route::post('/game/join', [GameController::class, 'join']);              // Unirse a una partida
    Route::post('/game/guess', [GameController::class, 'guess']);            // Intentar adivinar palabra
    Route::post('/game/leave', [GameController::class, 'abandon']);          // Abandonar partida
    Route::get('/game/current', [GameController::class, 'current']);         // Consultar partida actual
    Route::get('/game/history', [GameController::class, 'history']);         // Consultar historial de partidas
    Route::get('/game/available', [GameController::class, 'availableGames']); // Consultar partidas disponibles

    // Administrador
    Route::get('/admin/games', [AdminController::class, 'index']);           // Ver todos los juegos
    Route::post('/admin/activate', [AdminController::class, 'activateUser']); // Activar cuenta de usuario
    Route::post('/admin/desactivate', [AdminController::class, 'deactivate']); // Desactivar cuenta de usuario
    Route::post('/admin/promote', [AdminController::class, 'promoteToAdmin']); // Promover a administrador
});
