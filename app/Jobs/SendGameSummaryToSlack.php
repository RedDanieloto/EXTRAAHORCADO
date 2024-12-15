<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

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
     * @return void
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
        $attempts = $this->game->attempts()->get()->map(function ($attempt) {
            return "{$attempt->word} - " . ($attempt->is_correct ? "Correcto" : "Incorrecto");
        })->implode("\n");

        $message = "*Resumen del Juego*\n" .
            "Usuario: {$this->game->user->name}\n" .
            "Estado: {$this->status}\n" .
            "Palabra oculta: {$this->game->word}\n" .
            "Intentos:\n{$attempts}";

        Http::post(env('SLACK_WEBHOOK_URL'), [
            'text' => $message,
        ]);
    }
}