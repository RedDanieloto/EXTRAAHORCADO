<?php

namespace App\Http\Controllers;

use App\Jobs\SendGameSummaryToSlack;
use App\Models\Game;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Twilio\Rest\Client;

class GameController extends Controller
{
    protected $twilio;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->twilio = new Client(env('TWILIO_SID'), env('TWILIO_AUTH_TOKEN'));
    }

    // Verificar si la cuenta del usuario está activa
    private function ensureAccountIsActive()
    {
        $user = Auth::user();

        if (!$user->is_active) {
            DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->delete();
            response()->json([
                'mensaje' => $user->deactivation_reason === 'admin_disabled'
                    ? 'Tu cuenta está desactivada por un administrador.'
                    : 'Tu cuenta está desactivada. Contacta a un administrador para reactivarla.',
            ], 403)->send();
            exit;
        }
    }

    // Crear una nueva partida
    public function create()
    {
        $this->ensureAccountIsActive();

        $user = Auth::user();
        $word = $this->fetchRandomWord();

        if (!$word) {
            return response()->json(['mensaje' => 'No se pudo obtener una palabra válida.'], 500);
        }

        $maxAttempts = env('AHORCADO_MAX_ATTEMPTS', 7);

        $game = Game::create([
            'user_id' => $user->id,
            'word' => $word,
            'remaining_attempts' => $maxAttempts,
            'is_active' => false,
            'status' => 'por empezar',
            'progress' => str_repeat('_', strlen($word)),
            'letters_attempted' => [],
        ]);

        return response()->json([
            'mensaje' => 'Partida creada correctamente.',
            'partida' => [
                'id' => $game->id,
                'status' => $game->status,
                'word_length' => strlen($word),
                'intentos_restantes' => $maxAttempts,
            ],
        ], 201);
    }

    private function fetchRandomWord()
    {
        $response = Http::get('https://clientes.api.greenborn.com.ar/public-random-word');
        if ($response->successful()) {
            $word = trim($response->body(), '[]" ');
            return (strlen($word) >= 4 && strlen($word) <= 8 && !preg_match('/[ñáéíóú]/i', $word))
                ? strtolower($word)
                : null;
        }
        return null;
    }

    // Consultar partidas disponibles
    public function availableGames()
    {
        $this->ensureAccountIsActive();

        $user = Auth::user();
        $games = Game::where('user_id', $user->id)
            ->where('status', 'por empezar')
            ->get()
            ->map(function ($game) {
                $game->word_length = strlen($game->word);
                unset($game->word);
                return $game;
            });

        return $games->isEmpty()
            ? response()->json(['mensaje' => 'No hay partidas disponibles.'], 404)
            : response()->json(['partidas_disponibles' => $games], 200);
    }
    public function abandon()
{
    $this->ensureAccountIsActive();

    $user = Auth::user();

    // Buscar si hay una partida activa del usuario
    $game = Game::where('active_player_id', $user->id)->where('is_active', true)->first();

    // Validar si no hay una partida activa para abandonar
    if (!$game) {
        return response()->json(['mensaje' => 'No tienes ninguna partida activa la cual abandonar.'], 404);
    }

    // Actualizar el estado de la partida como abandonada
    $game->update(['is_active' => false, 'status' => 'abandonada']);

    // Enviar resumen a Slack con retraso de un minuto
    SendGameSummaryToSlack::dispatch($game, 'Abandonada')->delay(now()->addMinute());

    // Enviar notificación por Twilio
    $this->sendTwilioMessage(
        $user->phone,
        'Has abandonado la partida.',
        implode(' ', str_split($game->progress)),
        $game->remaining_attempts
    );

    return response()->json(['mensaje' => 'Has abandonado la partida.'], 200);
}

    // Unirse a una partida
    public function join(Request $request)
    {
        $this->ensureAccountIsActive();
    
        $data = $request->validate(['game_id' => 'required|exists:games,id']);
        $user = Auth::user();
    
        // Verificar si el usuario ya tiene una partida activa
        $activeGame = Game::where('active_player_id', $user->id)->where('is_active', true)->first();
        if ($activeGame) {
            return response()->json([
                'mensaje' => 'Ya tienes una partida activa. Termina o abandona tu partida actual.',
                'partida_activa' => [
                    'id' => $activeGame->id,
                    'status' => $activeGame->status,
                    'progreso' => implode(' ', str_split($activeGame->progress)), // Progreso con guiones
                    'intentos_restantes' => $activeGame->remaining_attempts,
                    'creado_en' => $activeGame->created_at,
                ],
            ], 400);
        }
    
        // Verificar que la partida exista y esté disponible
        $game = Game::find($data['game_id']);
        if ($game->user_id !== $user->id || $game->status !== 'por empezar') {
            return response()->json(['mensaje' => 'No puedes unirte a esta partida.'], 403);
        }
    
        // Actualizar la partida como "en progreso"
        $game->update([
            'active_player_id' => $user->id,
            'status' => 'en progreso',
            'is_active' => true,
        ]);
    
        return response()->json([
            'mensaje' => 'Te has unido correctamente a la partida.',
            'partida' => [
                'id' => $game->id,
                'word_length' => strlen($game->word),
                'intentos_restantes' => $game->remaining_attempts,
            ],
        ]);
    }

    // Adivinar una letra
    public function guess(Request $request)
    {
        $this->ensureAccountIsActive();

        $data = $request->validate(['letter' => 'required|string|size:1|regex:/^[a-zA-Z]$/']);
        $user = Auth::user();

        $game = Game::where('active_player_id', $user->id)->where('is_active', true)->first();
        if (!$game) {
            return response()->json(['mensaje' => 'No tienes ninguna partida activa.'], 404);
        }

        $letter = strtolower($data['letter']);
        $lettersAttempted = $game->letters_attempted ?? [];

        if (in_array($letter, $lettersAttempted)) {
            return response()->json(['mensaje' => 'Letra ya intentada.'], 400);
        }

        $lettersAttempted[] = $letter;
        $game->letters_attempted = $lettersAttempted;

        $word = str_split($game->word);
        $progress = str_split($game->progress);

        $found = false;
        for ($i = 0; $i < count($word); $i++) {
            if ($word[$i] === $letter) {
                $progress[$i] = $letter;
                $found = true;
            }
        }

        if (!$found) {
            $game->decrement('remaining_attempts');
        }

        $game->progress = implode('', $progress);
        $game->save();

        $formattedProgress = implode(' ', $progress);

        if ($game->progress === $game->word) {
            $game->update(['is_active' => false, 'status' => 'ganada']);
            SendGameSummaryToSlack::dispatch($game, 'Ganada')->delay(now()->addMinute());
            $this->sendTwilioMessage($user->phone, '¡Ganaste!', $formattedProgress, $game->remaining_attempts);
            return response()->json(['mensaje' => '¡Ganaste!', 'progreso' => $formattedProgress]);
        }

        if ($game->remaining_attempts <= 0) {
            $game->update(['is_active' => false, 'status' => 'perdida']);
            SendGameSummaryToSlack::dispatch($game, 'Perdida')->delay(now()->addMinute());
            $this->sendTwilioMessage($user->phone, 'Has perdido.', $formattedProgress, $game->remaining_attempts);
            return response()->json(['mensaje' => 'Has perdido.', 'progreso' => $formattedProgress]);
        }

        $this->sendTwilioMessage($user->phone, $found ? 'Letra correcta' : 'Letra incorrecta', $formattedProgress, $game->remaining_attempts);

        return response()->json([
            'mensaje' => $found ? 'Letra correcta.' : 'Letra incorrecta.',
            'progreso' => $formattedProgress,
            'intentos_restantes' => $game->remaining_attempts,
        ]);
    }

    // Partida actual
    public function current()
    {
        $this->ensureAccountIsActive();

        $game = Game::where('active_player_id', Auth::id())->where('is_active', true)->first();
        if (!$game) return response()->json(['mensaje' => 'No tienes ninguna partida activa.'], 404);

        return response()->json([
            'partida_actual' => [
                'progreso' => implode(' ', str_split($game->progress)),
                'intentos_restantes' => $game->remaining_attempts,
            ],
        ]);
    }

    // Historial de partidas
    public function history()
    {
        $this->ensureAccountIsActive();

        $games = Game::where('user_id', Auth::id())->whereIn('status', ['ganada', 'perdida', 'abandonada'])->get();
        return response()->json(['historial' => $games]);
    }

    private function sendTwilioMessage($to, $message, $progress, $remainingAttempts)
    {
        $body = "{$message} | Progreso: {$progress} | Intentos restantes: {$remainingAttempts}";
        $this->twilio->messages->create("whatsapp:{$to}", [
            'from' => 'whatsapp:' . env('TWILIO_WHATSAPP_NUMBER'),
            'body' => $body,
        ]);
    }
}