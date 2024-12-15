<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('word'); // Palabra a adivinar
            $table->boolean('is_active')->default(true); // Indica si el juego está activo
            $table->integer('remaining_attempts')->env('AHORCADO_MAX_ATTEMPTS'); // Intentos restantes
            $table->string('status')->default('por empezar'); // Estado del juego: 'por empezar', 'en progreso', 'finalizado', 'abandonada'
            $table->foreignId('active_player_id')->nullable()->constrained('users')->onDelete('set null'); // Jugador activo
            $table->json('letters_attempted')->nullable(); // Letras intentadas, almacenadas como JSON
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('games');
    }
};