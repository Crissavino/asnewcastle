<?php

// Enlaces de descarga que muestra la guía de invitación (Auth/Sumate).
// Android: APK directo servido desde /public. iOS: link de la beta de TestFlight
// (+ la app TestFlight en la App Store, que hace falta antes).
return [
    'apk_url' => env('ANDROID_APK_URL', '/asnewcastle.apk'),
    'testflight_url' => env('IOS_TESTFLIGHT_URL', 'https://testflight.apple.com/join/srcYyWB2'),
    'testflight_appstore_url' => 'https://apps.apple.com/app/testflight/id899247664',
];
