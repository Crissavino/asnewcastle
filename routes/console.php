<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('eventos:recordar')->hourly();
Schedule::command('cuotas:generar')->monthlyOn(1, '06:00');
