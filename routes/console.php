<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('eventos:recordar')->hourly();
Schedule::command('cuotas:generar')->monthlyOn(1, '06:00');
Schedule::command('tabla:importar')->dailyAt('07:00');
Schedule::command('figura:abrir')->hourly();
