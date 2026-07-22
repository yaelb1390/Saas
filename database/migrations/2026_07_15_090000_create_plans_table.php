<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planes de suscripción configurables por el operador de la plataforma. Cada plan agrupa un
 * conjunto de módulos, un precio y un ciclo de facturación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->string('billing_cycle')->default('monthly'); // monthly, quarterly, yearly
            $table->unsignedInteger('trial_days')->default(0);
            $table->json('modules')->nullable();                 // null = todos los módulos
            $table->unsignedInteger('max_users')->nullable();    // null = sin límite
            $table->unsignedInteger('max_branches')->nullable();
            $table->boolean('is_active')->default(true);         // visible para asignar
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
