<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sincronización diaria del estado de la licencia con el servidor remoto.
// Si no hay internet, el comando falla silenciosamente sin afectar la operaria.
Schedule::command('license:sync')->daily()->withoutOverlapping();
