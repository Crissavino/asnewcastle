<?php

// Enlaces de descarga que muestra la guía de invitación (Auth/Sumate).
// Android: APK directo servido desde /public. iOS: link de la beta de TestFlight
// (+ la app TestFlight en la App Store, que hace falta antes).
return [
    'apk_url' => env('ANDROID_APK_URL', '/asnewcastle.apk'),
    'testflight_url' => env('IOS_TESTFLIGHT_URL', 'https://testflight.apple.com/join/srcYyWB2'),
    'testflight_appstore_url' => 'https://apps.apple.com/app/testflight/id899247664',

    // versionCode del APK que está publicado en /asnewcastle.apk. La app nativa
    // Android compara su propio versionCode con éste: si el suyo es menor, propone
    // bajar el APK nuevo (el sideload no auto-actualiza como Play/TestFlight).
    // OJO: subir esto en cada release de APK, junto con android/app/build.gradle.
    'apk_version_code' => (int) env('ANDROID_APK_VERSION_CODE', 3),
];
