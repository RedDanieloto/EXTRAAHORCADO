<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use App\Models\Game;

class AdminController extends Controller
{
    // Código especial para registro como administrador

    // Verificar si el usuario autenticado es admin
    private function authorizeAdmin()
    {
        $user = Auth::user();
        if (!$user || $user->role !== 'admin') {
            response()->json(['message' => 'No tienes el rango necesario para acceder.'], 403)->send();
            exit;
        }
    }

    // Registro para crear un administrador utilizando un código especial
    
    // Listar todos los juegos y sus resultados
    public function index()
    {
        $this->authorizeAdmin();

        $games = Game::with(['user', 'attempts'])->get();
        return response()->json(['games' => $games]);
    }

    // Desactivar cuenta de usuario
    public function deactivate(Request $request)
    {
        $this->authorizeAdmin();

        $data = $request->validate(['user_id' => 'required|exists:users,id']);

        $user = User::find($data['user_id']);

        // Validar si el usuario ya está desactivado
        if (!$user->is_active) {
            return response()->json(['message' => 'El usuario ya está desactivado.'], 400);
        }

        $user->update(['is_active' => false]);

        return response()->json(['message' => 'Usuario desactivado exitosamente.']);
    }

    // Promover un usuario a administrador
    public function promoteToAdmin(Request $request)
    {
        $this->authorizeAdmin();

        $data = $request->validate(['user_id' => 'required|exists:users,id']);

        $user = User::find($data['user_id']);

        // Validar si el usuario ya está desactivado
        if (!$user->is_active) {
            return response()->json(['message' => 'No se puede promover a administrador a un usuario desactivado.'], 400);
        }

        // Validar si el usuario ya es administrador
        if ($user->role === 'admin') {
            return response()->json(['message' => 'El usuario ya es administrador.'], 400);
        }

        $user->update(['role' => 'admin']);

        return response()->json(['message' => 'El usuario ha sido promovido a administrador.', 'user' => $user]);
    }
}