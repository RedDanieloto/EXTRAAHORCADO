<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Game extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'word',
        'progress', // Progreso parcial de la palabra en el juego de ahorcado
        'remaining_attempts',
        'is_active',
        'status', // Estado del juego: 'por empezar', 'en progreso', 'finalizado', 'abandonada'
        'active_player_id', // Jugador activo
        'letters_attempted', // Letras intentadas
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'letters_attempted' => 'array', // Almacenar las letras intentadas como JSON
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function attempts()
    {
        return $this->hasMany(Attempt::class);
    }
}