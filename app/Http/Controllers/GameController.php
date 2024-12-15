<?php

namespace App\Http\Controllers;

use App\Jobs\SendGameSummaryToSlack;
use App\Models\Game;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

    // Crear una nueva partida
    public function create()
    {
        $user = Auth::user();

        $palabra = 'Secreto';
        $intentos = 5;

        while ($intentos > 0) {
            $response = Http::get('https://clientes.api.greenborn.com.ar/public-random-word');
            if ($response->successful()) {
                $palabraCandidata = trim($response->body(), '[]" ');
                if (strlen($palabraCandidata) >= 4 && strlen($palabraCandidata) <= 8) {
                    $palabra = $palabraCandidata;
                    break;
                }
            }
            $intentos--;
        }

        if (! $palabra) {
            return response()->json(['mensaje' => 'No se pudo obtener una palabra válida.'], 500);
        }

        $game = Game::create([
            'user_id' => $user->id,
            'word' => $palabra,
            'remaining_attempts' => env('WORDLE_MAX_ATTEMPTS', 5),
            'is_active' => false,
            'status' => 'por empezar',
        ]);

        return response()->json([
            'mensaje' => 'Partida creada correctamente.',
            'partida' => [
                'id' => $game->id,
                'status' => $game->status,
                'word_length' => strlen($palabra),
                'intentos_restantes' => env('WORDLE_MAX_ATTEMPTS', 5),
                'creado_por' => $user,
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
                return $this->maskWordIfActive($game);
            });

        if ($games->isEmpty()) {
            return response()->json(['mensaje' => 'No hay partidas disponibles.'], 404);
        }

        return response()->json(['partidas_disponibles' => $games], 200);
    }

    // Función para ocultar la palabra y mostrar solo su longitud
    private function maskWordIfActive($game)
    {
        // Calcular la longitud de la palabra ANTES de ocultarla
        $game->word_length = strlen($game->word);

        // Ocultar la palabra si la partida está en progreso o por empezar
        if (in_array($game->status, ['en progreso', 'por empezar'])) {
            unset($game->word);
        }

        return $game;
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
                'partida_activa' => $this->maskWordIfActive($activeGame),
            ], 400);
        }

        $game = Game::where('id', $data['game_id'])
            ->where('user_id', $user->id)
            ->where('status', 'por empezar')
            ->first();

        if (! $game) {
            return response()->json(['mensaje' => 'No puedes unirte a esta partida porque no te pertenece o no está disponible.'], 403);
        }

        $game->update(['active_player_id' => $user->id, 'status' => 'en progreso', 'is_active' => true]);

        return response()->json([
            'mensaje' => 'Te has unido correctamente a la partida.',
            'partida' => $this->maskWordIfActive($game),
        ], 200);
    }

    // Abandonar la partida
    public function abandon()
    {
        $user = Auth::user();

        $activeGame = Game::where('active_player_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (! $activeGame) {
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
    // Historial de partidas (solo terminadas o abandonadas)
    public function history()
    {
        $user = Auth::user();

        $games = Game::where('user_id', $user->id)
            ->whereIn('status', ['ganada', 'perdida', 'abandonada']) // Filtrar solo las partidas terminadas
            ->get()
            ->map(function ($game) {
                return $this->maskWordIfActive($game); // Usar la función para ocultar palabras activas
            });

        if ($games->isEmpty()) {
            return response()->json(['mensaje' => 'No tienes partidas registradas que hayan terminado.'], 404);
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

        if (! $game) {
            return response()->json(['mensaje' => 'No tienes ninguna partida activa.'], 404);
        }

        return response()->json(['partida_actual' => $this->maskWordIfActive($game)], 200);
    }

    // Adivinar palabra
    public function guess(Request $request)
    {
        $user = Auth::user();

        $data = $request->validate([
            'word' => 'required|string|regex:/^[a-zA-Z]+$/',
        ]);

        $game = Game::where('active_player_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (! $game) {
            return response()->json(['mensaje' => 'No tienes ninguna partida activa.'], 404);
        }

        $attempt = $data['word'];
        $correctWord = $game->word;

        if (strlen($attempt) !== strlen($correctWord)) {
            return response()->json(['mensaje' => 'La palabra debe tener '.strlen($correctWord).' letras.'], 400);
        }

        $pistas = '';
        for ($i = 0; $i < strlen($correctWord); $i++) {
            $pistas .= ($attempt[$i] ?? '') === $correctWord[$i] ? $correctWord[$i] : '-';
        }

        if ($attempt === $correctWord) {
            $game->update(['is_active' => false, 'status' => 'ganada']);
            $this->sendSlackSummary($game, 'Ganada');
            $this->sendTwilioMessage($user->phone, "¡Felicidades! Has ganado. La palabra era: $correctWord.");

            return response()->json(['mensaje' => '¡Felicidades! Has ganado.'], 200);
        }

        $game->decrement('remaining_attempts');

        if ($game->remaining_attempts <= 0) {
            $game->update(['is_active' => false, 'status' => 'perdida']);
            $this->sendSlackSummary($game, 'Perdida');
            $this->sendTwilioMessage($user->phone, "Has perdido. La palabra era: $correctWord.");

            return response()->json(['mensaje' => 'Has perdido. La palabra era: '.$correctWord], 200);
        }

        $this->sendTwilioMessage($user->phone, "Intento: $attempt | Pistas: $pistas | Intentos restantes: {$game->remaining_attempts}.");

        return response()->json(['pistas' => $pistas], 200);
    }

    private function sendSlackSummary($game, $estado)
    {
        SendGameSummaryToSlack::dispatch($game, $estado)->delay(now()->addMinute());
    }

    private function sendTwilioMessage($to, $message)
    {
        $this->twilio->messages->create('whatsapp:'.$to, [
            'from' => 'whatsapp:'.env('TWILIO_WHATSAPP_NUMBER'),
            'body' => $message,
        ]);
    }
}
