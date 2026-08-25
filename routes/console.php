<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Respaldo diario de la base de datos (spatie/laravel-backup). Primero limpia los respaldos viejos
 * según la política de retención y luego genera el del día. Lo ejecuta el contenedor `scheduler`
 * (php artisan schedule:work). El destino externo (Cloudflare R2) se activa al configurar R2_* en
 * el .env; mientras tanto se guarda en el disco local.
 */
Schedule::command('backup:clean')->dailyAt('01:00')->withoutOverlapping();
Schedule::command('backup:run')->dailyAt('01:30')->withoutOverlapping();

// Borra los datos de las pruebas self-service vencidas hace >24 h (conserva la cuenta). En Vercel lo
// dispara el cron (endpoint /tareas/purgar-pruebas); aquí queda para entornos con scheduler.
Schedule::command('trials:purge')->dailyAt('06:00')->withoutOverlapping();

// Avisa por correo a las suscripciones de pago que están por vencer (umbral del plan). En Vercel lo
// dispara el cron (endpoint /tareas/avisar-vencimientos); aquí para entornos con scheduler.
Schedule::command('subscriptions:remind-expiring')->dailyAt('07:00')->withoutOverlapping();

/*
 * Borra los mensajes de WhatsApp más viejos que la retención que haya pedido cada empresa.
 *
 * De madrugada porque borrar en bloque bloquea filas, y a esa hora no hay nadie en la bandeja. No
 * borra nada por su cuenta: la retención viene en cero de fábrica y hay que encenderla a propósito
 * (ver PurgeOldConversations), así que esta línea puede estar activa sin que se pierda ni un mensaje.
 */
Schedule::command('conversaciones:purgar')->dailyAt('03:00')->withoutOverlapping();
