<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Game;
use App\Models\Word;
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
    public function create(Request $request)
    {
        $user = Auth::user();

        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        if ($user->id !== $data['user_id']) {
            return response()->json(['message' => 'No tienes permiso para crear esta partida.'], 403);
        }

        // Generar una palabra aleatoria usando el factory
        $word = Word::factory()->make();

        $existingGame = Game::where('user_id', $user->id)->where('is_active', true)->first();
        if ($existingGame) {
            return response()->json(['message' => 'Ya tienes una partida activa.'], 400);
        }

        $game = Game::create([
            'user_id' => $user->id,
            'word' => $word->word,
            'remaining_attempts' => env('WORDLE_MAX_ATTEMPTS', 5),
        ]);

        // Retornar solo información básica del juego, sin mostrar la palabra
        return response()->json([
            'message' => 'Partida creada exitosamente.',
            'game' => [
                'id' => $game->id,
                'user_id' => $game->user_id,
                'remaining_attempts' => $game->remaining_attempts,
                'created_at' => $game->created_at,
                'updated_at' => $game->updated_at,
            ],
        ]);
    }

    // Enviar un intento de palabra
    public function guess(Request $request)
    {
        $user = Auth::user();

        $data = $request->validate([
            'game_id' => 'required|exists:games,id',
            'word' => 'required|string',
        ]);

        $game = Game::find($data['game_id']);

        if (!$game || !$game->is_active) {
            return response()->json(['message' => 'El juego no está activo o no existe.'], 404);
        }

        if ($game->user_id !== $user->id) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $attempt = $data['word'];
        $correctWord = $game->word;

        if (strlen($attempt) !== strlen($correctWord)) {
            return response()->json(['message' => 'La palabra debe tener exactamente ' . strlen($correctWord) . ' caracteres.'], 400);
        }

        $pistas = '';
        for ($i = 0; $i < strlen($correctWord); $i++) {
            $pistas .= (isset($attempt[$i]) && $attempt[$i] === $correctWord[$i]) ? $correctWord[$i] : '-';
        }

        $game->attempts()->create([
            'word' => $attempt,
            'is_correct' => $attempt === $correctWord,
        ]);

        if ($attempt === $correctWord) {
            $game->update(['is_active' => false]);
            SendGameSummaryToSlack::dispatch($game, 'Ganado')->delay(now()->addMinute());

            try {
                $this->twilio->messages->create(
                    "whatsapp:" . $user->phone,
                    [
                        'from' => 'whatsapp:' . env('TWILIO_WHATSAPP_NUMBER'),
                        'body' => "¡Felicidades! Has adivinado la palabra: $correctWord",
                    ]
                );
            } catch (\Exception $e) {
                return response()->json(['message' => 'Error al enviar el mensaje: ' . $e->getMessage()], 500);
            }

            return response()->json(['message' => '¡Correcto! Ganaste el juego.']);
        }

        $game->decrement('remaining_attempts');

        if ($game->remaining_attempts <= 0) {
            $game->update(['is_active' => false]);
            SendGameSummaryToSlack::dispatch($game, 'Perdido')->delay(now()->addMinute());;

            try {
                $this->twilio->messages->create(
                    "whatsapp:" . $user->phone,
                    [
                        'from' => 'whatsapp:' . env('TWILIO_WHATSAPP_NUMBER'),
                        'body' => "Has perdido la partida. La palabra era: $correctWord",
                    ]
                );
            } catch (\Exception $e) {
                return response()->json(['message' => 'Error al enviar el mensaje: ' . $e->getMessage()], 500);
            }

            return response()->json(['message' => 'Has perdido el juego.']);
        }

        try {
            $this->twilio->messages->create(
                "whatsapp:" . $user->phone,
                [
                    'from' => 'whatsapp:' . env('TWILIO_WHATSAPP_NUMBER'),
                    'body' => "Intento: $attempt | Pistas: $pistas",
                ]
            );
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al enviar el mensaje: ' . $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Intenta de nuevo.', 'remaining_attempts' => $game->remaining_attempts]);
    }

    // Abandonar la partida actual
    public function abandon(Request $request)
    {
        $user = Auth::user();

        $game = Game::where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (!$game) {
            return response()->json(['message' => 'No tienes ninguna partida activa para abandonar.'], 404);
        }

        $game->update(['is_active' => false]);
        $game->attempts()->create([
            'word' => 'abandonado',
            'is_correct' => false,
        ]);

        SendGameSummaryToSlack::dispatch($game, 'Abandonado')->delay(now()->addMinute());;

        try {
            $this->twilio->messages->create(
                "whatsapp:" . $user->phone,
                [
                    'from' => 'whatsapp:' . env('TWILIO_WHATSAPP_NUMBER'),
                    'body' => "Has abandonado la partida. La palabra oculta era: {$game->word}",
                ]
            );
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al enviar el mensaje: ' . $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Has abandonado la partida.']);
    }

    public function current(Request $request)
    {
        $user = Auth::user();

        $game = Game::where('user_id', $user->id)
            ->where('is_active', true)
            ->with('attempts')
            ->first();

        if (!$game) {
            return response()->json(['message' => 'No tienes ninguna partida activa.'], 404);
        }

        return response()->json(['game' => $game]);
    }

    public function history(Request $request)
    {
        $user = Auth::user();

        $games = Game::where('user_id', $user->id)
            ->with('attempts')
            ->get();

        if ($games->isEmpty()) {
            return response()->json(['message' => 'No tienes partidas registradas.'], 404);
        }

        return response()->json(['games' => $games]);
    }
}