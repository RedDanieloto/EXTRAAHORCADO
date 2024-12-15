<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use App\Models\Game;
use App\Jobs\SendGameSummaryToSlack;
use Twilio\Rest\Client;

class GameController extends Controller
{
    protected $twilio;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->twilio = new Client(env('TWILIO_SID'), env('TWILIO_AUTH_TOKEN'));
    }

    // Crear una nueva partida
    public function create()
    {
        $user = Auth::user();
        $palabra = null;
        $maxAttempts = env('AHORCADO_MAX_ATTEMPTS'); // Obtener intentos del .env

        while ($maxAttempts > 0) {
            $response = Http::get('https://clientes.api.greenborn.com.ar/public-random-word');
            if ($response->successful()) {
                $palabraCandidata = trim($response->body(), '[]" ');
                if (strlen($palabraCandidata) >= 4 && strlen($palabraCandidata) <= 8) {
                    $palabra = strtolower($palabraCandidata);
                    break;
                }
            }
            $maxAttempts--;
        }

        if (!$palabra) {
            return response()->json(['mensaje' => 'No se pudo obtener una palabra válida.'], 500);
        }

        $game = Game::create([
            'user_id' => $user->id,
            'word' => $palabra,
            'remaining_attempts' => $maxAttempts, // Inicializar intentos
            'is_active' => false,
            'status' => 'por empezar',
            'letters_attempted' => [],
        ]);

        return response()->json([
            'mensaje' => 'Partida creada correctamente.',
            'partida' => [
                'id' => $game->id,
                'status' => $game->status,
                'word_length' => strlen($palabra),
                'intentos_restantes' => $game->remaining_attempts,
            ],
        ], 201);
    }

    // Consultar partidas disponibles
    public function availableGames()
    {
        $user = Auth::user();

        $games = Game::where('user_id', $user->id)
            ->where('status', 'por empezar')
            ->get()
            ->map(function ($game) {
                $game->word_length = strlen($game->word);
                unset($game->word);
                return $game;
            });

        if ($games->isEmpty()) {
            return response()->json(['mensaje' => 'No hay partidas disponibles.'], 404);
        }

        return response()->json(['partidas_disponibles' => $games], 200);
    }

    // Unirse a una partida
    public function join(Request $request)
    {
        $user = Auth::user();

        $data = $request->validate([
            'game_id' => 'required|exists:games,id',
        ]);

        $activeGame = Game::where('active_player_id', $user->id)
            ->where('is_active', true)
            ->first();

        if ($activeGame) {
            return response()->json([
                'mensaje' => 'Ya tienes una partida activa.',
                'partida_activa' => $activeGame,
            ], 400);
        }

        $game = Game::where('id', $data['game_id'])
            ->where('user_id', $user->id)
            ->where('status', 'por empezar')
            ->first();

        if (!$game) {
            return response()->json(['mensaje' => 'No puedes unirte a esta partida porque no te pertenece o no está disponible.'], 403);
        }

        $game->update(['active_player_id' => $user->id, 'status' => 'en progreso', 'is_active' => true]);

        return response()->json([
            'mensaje' => 'Te has unido correctamente a la partida.',
            'partida' => [
                'id' => $game->id,
                'status' => $game->status,
                'word_length' => strlen($game->word),
                'intentos_restantes' => $game->remaining_attempts,
            ],
        ], 200);
    }

    // Adivinar una letra
    public function guess(Request $request)
    {
        $user = Auth::user();

        $data = $request->validate([
            'letter' => 'required|string|size:1|regex:/^[a-zA-Z]+$/',
        ]);

        $game = Game::where('active_player_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (!$game) {
            return response()->json(['mensaje' => 'No tienes ninguna partida activa.'], 404);
        }

        $letter = strtolower($data['letter']);
        if (in_array($letter, $game->letters_attempted)) {
            return response()->json(['mensaje' => 'Ya intentaste esta letra.'], 400);
        }

        $lettersAttempted = $game->letters_attempted;
        $lettersAttempted[] = $letter;
        $game->update(['letters_attempted' => $lettersAttempted]);

        $correctLetters = array_intersect(str_split($game->word), $lettersAttempted);

        $maskedWord = '';
        foreach (str_split($game->word) as $char) {
            $maskedWord .= in_array($char, $correctLetters) ? $char : '_';
        }

        // Validar si la palabra se completó
        if ($maskedWord === $game->word) {
            $game->update(['is_active' => false, 'status' => 'ganada']);
            $this->sendSlackSummary($game, 'Ganada');
            $this->sendTwilioMessage($user->phone, "¡Felicidades! Has ganado. La palabra era: {$game->word}.");
            return response()->json(['mensaje' => '¡Felicidades! Has ganado.', 'palabra' => $game->word], 200);
        }

        // Validar si la letra es incorrecta y decrementar intentos
        if (!in_array($letter, str_split($game->word))) {
            $game->decrement('remaining_attempts');
        }

        // Validar si los intentos se agotaron
        if ($game->remaining_attempts <= 0) {
            $game->update(['is_active' => false, 'status' => 'perdida']);
            $this->sendSlackSummary($game, 'Perdida');
            $this->sendTwilioMessage($user->phone, "Has perdido. La palabra era: {$game->word}.");
            return response()->json(['mensaje' => 'Has perdido. La palabra era: ' . $game->word], 200);
        }

        $this->sendTwilioMessage($user->phone, "Intento: $letter | Progreso: $maskedWord | Intentos restantes: {$game->remaining_attempts}.");
        return response()->json(['progreso' => $maskedWord, 'intentos_restantes' => $game->remaining_attempts], 200);
    }

    // Abandonar la partida
    public function abandon()
    {
        $user = Auth::user();

        $activeGame = Game::where('active_player_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (!$activeGame) {
            return response()->json(['mensaje' => 'No tienes ninguna partida activa.'], 404);
        }

        $activeGame->update([
            'is_active' => false,
            'status' => 'abandonada',
        ]);

        $this->sendSlackSummary($activeGame, 'Abandonada');
        $this->sendTwilioMessage($user->phone, "Has abandonado la partida. La palabra era: {$activeGame->word}");

        return response()->json(['mensaje' => 'Has abandonado la partida.'], 200);
    }

    // Historial de partidas
    public function history()
    {
        $user = Auth::user();

        $games = Game::where('user_id', $user->id)
            ->whereIn('status', ['ganada', 'perdida', 'abandonada'])
            ->get();

        if ($games->isEmpty()) {
            return response()->json(['mensaje' => 'No tienes partidas registradas.'], 404);
        }

        return response()->json(['historial' => $games], 200);
    }

    // Partida actual
    public function current()
    {
        $user = Auth::user();

        $game = Game::where('active_player_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (!$game) {
            return response()->json(['mensaje' => 'No tienes ninguna partida activa.'], 404);
        }

        return response()->json(['partida_actual' => $game], 200);
    }

    // Enviar resumen a Slack
    private function sendSlackSummary($game, $estado)
    {
        SendGameSummaryToSlack::dispatch($game, $estado)->delay(now()->addMinute());
    }

    // Enviar mensaje por Twilio
    private function sendTwilioMessage($to, $message)
    {
        $this->twilio->messages->create("whatsapp:" . $to, [
            'from' => 'whatsapp:' . env('TWILIO_WHATSAPP_NUMBER'),
            'body' => $message,
        ]);
    }
}