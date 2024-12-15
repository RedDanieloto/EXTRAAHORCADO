<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WordsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $words = [
            ['word' => 'casa', 'length' => 4],
            ['word' => 'gato', 'length' => 4],
            ['word' => 'perro', 'length' => 5],
            ['word' => 'juego', 'length' => 5],
            ['word' => 'mesa', 'length' => 4],
            ['word' => 'carro', 'length' => 5],
            ['word' => 'mundo', 'length' => 5],
            ['word' => 'fuego', 'length' => 5],
            ['word' => 'tierra', 'length' => 6],
            ['word' => 'lluvia', 'length' => 6],
            ['word' => 'ciudad', 'length' => 6],
            ['word' => 'persona', 'length' => 7],
            ['word' => 'animal', 'length' => 6],
            ['word' => 'palabra', 'length' => 7],
            ['word' => 'ventana', 'length' => 7],
            ['word' => 'botella', 'length' => 7],
            ['word' => 'silla', 'length' => 5],
            ['word' => 'puerta', 'length' => 6],
            ['word' => 'sol', 'length' => 3],
            ['word' => 'luz', 'length' => 3],
        ];

        foreach ($words as $word) {
            DB::table('words')->insert([
                'word' => $word['word'],
                'length' => $word['length'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}