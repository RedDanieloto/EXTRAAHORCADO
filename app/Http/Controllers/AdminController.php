<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    // Verificar si el usuario autenticado es admin
    private function authorizeAdmin()
    {
        $user = Auth::user();
        if (!$user || $user->role !== 'admin') {
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

    // Activar cuenta de usuario por número de teléfono
    public function activateUser(Request $request)
    {
        $this->authorizeAdmin();

        $data = $request->validate([
            'phone' => 'required|exists:users,phone',
        ], [
            'phone.required' => 'El campo phone es obligatorio.',
            'phone.exists' => 'El número de teléfono especificado no existe.',
        ]);

        $user = User::where('phone', $data['phone'])->first();

        if ($user->is_active) {
            return response()->json(['message' => 'El usuario ya está activo.'], 400);
        }

        if ($user->deactivation_reason === 'admin_disabled') {
            return response()->json(['message' => 'Este usuario fue desactivado por un administrador y no puede reactivarse manualmente.'], 403);
        }

        $user->update(['is_active' => true, 'deactivation_reason' => null]);

        return response()->json(['message' => 'Usuario activado exitosamente.']);
    }

    // Desactivar cuenta de usuario por número de teléfono
    public function deactivate(Request $request)
    {
        $this->authorizeAdmin();

        $data = $request->validate([
            'phone' => 'required|exists:users,phone',
        ], [
            'phone.required' => 'El campo phone es obligatorio.',
            'phone.exists' => 'El número de teléfono especificado no existe.',
        ]);

        $user = User::where('phone', $data['phone'])->first();

        if (!$user->is_active) {
            return response()->json(['message' => 'El usuario ya está desactivado.'], 400);
        }

        // Desactivar usuario y agregar motivo
        $user->update([
            'is_active' => false,
            'deactivation_reason' => 'admin_disabled',
        ]);

        // Eliminar todos los tokens activos del usuario
        DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->delete();

        return response()->json(['message' => 'Usuario desactivado exitosamente.']);
    }

    // Promover un usuario a administrador por número de teléfono
    public function promoteToAdmin(Request $request)
    {
        $this->authorizeAdmin();

        $data = $request->validate([
            'phone' => 'required|exists:users,phone',
        ], [
            'phone.required' => 'El campo phone es obligatorio.',
            'phone.exists' => 'El número de teléfono especificado no existe.',
        ]);

        $user = User::where('phone', $data['phone'])->first();

        if (!$user->is_active) {
            return response()->json(['message' => 'No se puede promover a administrador a un usuario desactivado.'], 400);
        }

        if ($user->role === 'admin') {
            return response()->json(['message' => 'El usuario ya es administrador.'], 400);
        }

        $user->update(['role' => 'admin']);

        return response()->json(['message' => 'El usuario ha sido promovido a administrador.', 'user' => $user]);
    }
}