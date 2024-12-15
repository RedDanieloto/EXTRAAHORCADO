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
    public function registerAdmin(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|unique:users,phone|max:15',
            'password' => 'required|string|min:6',
            'admin_code' => 'required|string'
        ]);

        // Validar si el código proporcionado coincide con el código especial
        if ($data['admin_code'] !== self::ADMIN_CODE) {
            return response()->json(['message' => 'Código de administrador incorrecto.'], 403);
        }

        // Crear el usuario como administrador
        $user = User::create([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'password' => Hash::make($data['password']),
            'role' => 'admin',
            'is_active' => true,
        ]);

        return response()->json(['message' => 'Administrador registrado exitosamente.', 'user' => $user]);
    }

    public function sendVerification(Request $request)
{
    // Validar los datos recibidos
    $data = $request->validate([
        'name' => 'required|string|max:255',         // Nombre obligatorio, máximo 255 caracteres
        'phone' => 'required|string|unique:users,phone|max:15', // Teléfono único y máximo 15 caracteres
        'password' => 'required|string|min:6',      // Contraseña obligatoria, mínimo 6 caracteres
    ]);

    try {
        // Crear el usuario si no existe
        $user = User::create([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'password' => bcrypt($data['password']),
            'role' => 'player', 
            'is_active' => false,
        ]);

        // Generar código de verificación
        $code = rand(100000, 999999);

        // Guardar el código en caché
        Cache::put('verification_' . $data['phone'], $code, now()->addMinutes(10));

        // Enviar mensaje por WhatsApp
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
    public function verifyCode(Request $request)
    {
        $data = $request->validate([
            'phone' => 'required|string|exists:users,phone',
            'code' => 'required|string',
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

    public function logout(Request $request)
{
    // Revoke the user's token
    $request->user()->currentAccessToken()->delete();

    return response()->json(['message' => 'Sesión cerrada exitosamente.'], 200);
}

    public function login(Request $request)
    {
        $data = $request->validate([
            'phone' => 'required|string|exists:users,phone',
            'password' => 'required|string',
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