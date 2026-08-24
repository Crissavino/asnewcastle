<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('eventos:recordar')->hourly();
Schedule::command('cuotas:generar')->monthlyOn(1, '06:00');
Schedule::command('cuotas:avisar')->dailyAt('09:00');
Schedule::command('tabla:importar')->dailyAt('07:00');
Schedule::command('figura:abrir')->hourly();
Schedule::command('figura:cerrar')->hourly();
Schedule::command('legitimacion:purgar')->dailyAt('04:00');
