<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendGameSummaryToSlack implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $game;
    protected $status;

    /**
     * Create a new job instance.
     *
     * @param  $game
     * @param  string $status
     */
    public function __construct($game, $status)
    {
        $this->game = $game;
        $this->status = $status;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            // Cargar relaciones si es necesario
            $this->game->load('user');

            // Obtener letras intentadas desde el JSON en el modelo Game
            $lettersAttempted = $this->game->letters_attempted ?? [];
            $lettersList = !empty($lettersAttempted) ? implode(', ', $lettersAttempted) : 'Ninguna';

            // Obtener progreso parcial de la palabra
            $progress = '';
            foreach (str_split($this->game->word) as $char) {
                $progress .= in_array($char, $lettersAttempted) ? $char : '_';
            }

            // Construir el mensaje
            $message = "*Resumen del Juego - El Ahorcado*\n" .
                "Usuario: {$this->game->user->name}\n" .
                "Estado del juego: {$this->status}\n" .
                "Palabra oculta: {$this->game->word}\n" .
                "Progreso final: {$progress}\n" .
                "Letras intentadas: {$lettersList}\n" .
                "Intentos restantes: {$this->game->remaining_attempts}";

            // Enviar mensaje a Slack
            Http::post(env('SLACK_WEBHOOK_URL'), ['text' => $message]);

        } catch (\Exception $e) {
            Log::error("Error al enviar resumen a Slack: " . $e->getMessage());
            $this->fail($e);
        }
    }
}