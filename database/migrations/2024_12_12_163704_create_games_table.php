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
            $table->string('word');
            $table->boolean('is_active')->default(true);
            $table->integer('remaining_attempts')->default(5);
            $table->string('status')->default('por empezar'); // Estado del juego: 'por empezar', 'en progreso', 'finalizado'
            $table->foreignId('active_player_id')->nullable()->constrained('users')->onDelete('set null'); // Jugador activo
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
