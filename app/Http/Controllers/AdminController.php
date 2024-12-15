<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminController extends Controller
{
    // Verificar si el usuario autenticado es admin
    private function authorizeAdmin()
    {
        $user = Auth::user();
        if (! $user || $user->role !== 'admin') {
            // Enviar respuesta en lugar de un error genérico de Laravel
            response()->json(['message' => 'No tienes el rango necesario para acceder.'], 403)->send();
            exit;
        }
    }

    // Listar todos los juegos y sus resultados
    public function index()
    {
        $this->authorizeAdmin();

        $games = Game::with(['user', 'attempts'])->get();

        return response()->json(['games' => $games]);
    }

    // Activar cuenta de usuario
    public function activateUser(Request $request)
    {
        $this->authorizeAdmin();

        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
        ], [
            'user_id.required' => 'El campo user_id es obligatorio.',
            'user_id.exists' => 'El usuario especificado no existe.',
        ]);

        $user = User::find($data['user_id']);

        if ($user->is_active) {
            return response()->json(['message' => 'El usuario ya está activo.'], 400);
        }

        $user->update(['is_active' => true]);

        return response()->json(['message' => 'Usuario activado exitosamente.']);
    }

    // Desactivar cuenta de usuario
    public function deactivate(Request $request)
    {
        $this->authorizeAdmin();

        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
        ], [
            'user_id.required' => 'El campo user_id es obligatorio.',
            'user_id.exists' => 'El usuario especificado no existe.',
        ]);

        $user = User::find($data['user_id']);

        if (! $user->is_active) {
            return response()->json(['message' => 'El usuario ya está desactivado.'], 400);
        }

        $user->update(['is_active' => false]);

        return response()->json(['message' => 'Usuario desactivado exitosamente.']);
    }

    // Promover un usuario a administrador
    public function promoteToAdmin(Request $request)
    {
        $this->authorizeAdmin();

        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
        ], [
            'user_id.required' => 'El campo user_id es obligatorio.',
            'user_id.exists' => 'El usuario especificado no existe.',
        ]);

        $user = User::find($data['user_id']);

        if (! $user->is_active) {
            return response()->json(['message' => 'No se puede promover a administrador a un usuario desactivado.'], 400);
        }

        if ($user->role === 'admin') {
            return response()->json(['message' => 'El usuario ya es administrador.'], 400);
        }

        $user->update(['role' => 'admin']);

        return response()->json(['message' => 'El usuario ha sido promovido a administrador.', 'user' => $user]);
    }
}
