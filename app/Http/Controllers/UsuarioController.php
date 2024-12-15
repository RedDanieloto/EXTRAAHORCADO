<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Models\User;
use Twilio\Rest\Client;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class UsuarioController extends Controller
{
    private const ADMIN_CODE = '270905';
    protected $twilio;

    public function __construct()
    {
        $this->twilio = new Client(env('TWILIO_SID'), env('TWILIO_AUTH_TOKEN'));
    }

    // Registrar un administrador
    public function registerAdmin(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|unique:users,phone|max:15',
            'password' => 'required|string|min:6',
            'admin_code' => 'required|string'
        ], [
            'name.required' => 'El campo nombre es obligatorio.',
            'phone.required' => 'El campo teléfono es obligatorio.',
            'phone.unique' => 'El teléfono ya está registrado.',
            'password.required' => 'El campo contraseña es obligatorio.',
            'password.min' => 'La contraseña debe tener al menos 6 caracteres.',
            'admin_code.required' => 'El código de administrador es obligatorio.',
        ]);

        if ($data['admin_code'] !== self::ADMIN_CODE) {
            return response()->json(['message' => 'Código de administrador incorrecto.'], 403);
        }

        $user = User::create([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'password' => Hash::make($data['password']),
            'role' => 'admin',
            'is_active' => true,
        ]);

        return response()->json(['message' => 'Administrador registrado exitosamente.', 'user' => $user]);
    }

    // Enviar un código de verificación por WhatsApp
    public function sendVerification(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|unique:users,phone|max:15',
            'password' => 'required|string|min:6',
        ], [
            'name.required' => 'El campo nombre es obligatorio.',
            'phone.required' => 'El campo teléfono es obligatorio.',
            'phone.unique' => 'El teléfono ya está registrado.',
            'password.required' => 'El campo contraseña es obligatorio.',
            'password.min' => 'La contraseña debe tener al menos 6 caracteres.',
        ]);

        try {
            $user = User::create([
                'name' => $data['name'],
                'phone' => $data['phone'],
                'password' => bcrypt($data['password']),
                'role' => 'player',
                'is_active' => false,
            ]);

            $code = rand(100000, 999999);
            Cache::put('verification_' . $data['phone'], $code, now()->addMinutes(10));

            $this->twilio->messages->create(
                "whatsapp:" . $data['phone'],
                [
                    'from' => 'whatsapp:' . env('TWILIO_WHATSAPP_NUMBER'),
                    'body' => "Tu código de verificación es: $code. Por favor, no lo compartas con nadie.",
                ]
            );

            return response()->json(['message' => 'Código enviado exitosamente por WhatsApp.'], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al enviar el mensaje: ' . $e->getMessage()], 500);
        }
    }

    // Verificar un código de activación
    public function verifyCode(Request $request)
    {
        $data = $request->validate([
            'phone' => 'required|string|exists:users,phone',
            'code' => 'required|string',
        ], [
            'phone.required' => 'El campo teléfono es obligatorio.',
            'phone.exists' => 'El teléfono no está registrado.',
            'code.required' => 'El campo código es obligatorio.',
        ]);

        try {
            $storedCode = Cache::get('verification_' . $data['phone']);

            if ($storedCode && $storedCode == $data['code']) {
                $user = User::where('phone', $data['phone'])->first();
                $user->is_active = true;
                $user->save();

                Cache::forget('verification_' . $data['phone']);

                return response()->json(['message' => 'Número verificado correctamente.', 'verified' => true]);
            }

            return response()->json(['message' => 'Código inválido o expirado.', 'verified' => false], 400);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al verificar el código: ' . $e->getMessage()], 500);
        }
    }

    // Cerrar sesión
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Sesión cerrada exitosamente.'], 200);
    }

    // Iniciar sesión
    public function login(Request $request)
    {
        $data = $request->validate([
            'phone' => 'required|string|exists:users,phone',
            'password' => 'required|string',
        ], [
            'phone.required' => 'El campo teléfono es obligatorio.',
            'phone.exists' => 'El teléfono no está registrado.',
            'password.required' => 'El campo contraseña es obligatorio.',
        ]);

        $user = User::where('phone', $data['phone'])->first();

        if (!$user->is_active) {
            return response()->json(['message' => 'El número no ha sido verificado.'], 403);
        }

        if (!Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Contraseña incorrecta.'], 401);
        }

        $token = $user->createToken('User-Login')->plainTextToken;

        return response()->json([
            'message' => 'Login exitoso.',
            'token' => $token,
            'user' => $user,
        ]);
    }
}
