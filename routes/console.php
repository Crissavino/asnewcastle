<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('eventos:recordar')->hourly();
