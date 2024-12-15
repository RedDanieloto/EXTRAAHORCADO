<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id(); // ID único del usuario
            $table->string('name'); // Nombre del usuario
            $table->string('phone')->unique(); // Teléfono único
            $table->string('password'); // Contraseña encriptada
            $table->string('role')->default('player'); // Rol del usuario, por defecto 'player'
            $table->boolean('is_active')->default(false); // Estado de activación de la cuenta
            $table->timestamps(); // Marcas de tiempo created_at y updated_at
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('users'); // Elimina la tabla si se revierte la migración
    }
}