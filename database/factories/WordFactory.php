<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class WordFactory extends Factory
{
    /**
     * Define el modelo asociado al factory.
     *
     * @var string
     */
    protected $model = \App\Models\Word::class;

    /**
     * Define la estructura de las palabras generadas.
     *
     * @return array
     */
    public function definition()
    {
        // Longitud aleatoria entre el mínimo y máximo definidos en el archivo .env
        $minLength = env('WORDLE_MIN_LENGTH', 4); // Valor por defecto: 4
        $maxLength = env('WORDLE_MAX_LENGTH', 8); // Valor por defecto: 8
        $length = rand($minLength, $maxLength);

        // Generar palabra aleatoria de longitud variable
        $word = $this->faker->lexify(str_repeat('?', $length)); // Generar letras aleatorias

        return [
            'word' => $word,
            'length' => strlen($word),
        ];
    }
}