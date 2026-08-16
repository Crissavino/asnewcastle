<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    'twilio' => [
        'sid' => env('TWILIO_ACCOUNT_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),
        'otp_template_sid' => env('TWILIO_OTP_TEMPLATE_SID'),
        'event_template_sid' => env('TWILIO_EVENT_TEMPLATE_SID'),
        'dues_template_sid' => env('TWILIO_DUES_TEMPLATE_SID'),
    ],

    'otp' => [
        // 'twilio' manda WhatsApp de verdad; 'log' escribe el código en el log (dev)
        'channel' => env('OTP_CHANNEL', 'twilio'),
        // Código maestro temporal mientras no anda Twilio: 7+ dígitos para no pisar
        // el espacio de los códigos reales (6). Sin la variable, no existe el bypass.
        // BORRAR del .env cuando el OTP salga por WhatsApp de verdad.
        'master_code' => env('OTP_MASTER_CODE'),
    ],

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        // Comisión de la plataforma en basis points (100 = 1%)
        'application_fee_bps' => (int) env('STRIPE_APPLICATION_FEE_BPS', 0),
    ],

    'whatsapp' => [
        // Avisos del club (convocatorias, recordatorios, cuotas)
        'channel' => env('WHATSAPP_CHANNEL', env('OTP_CHANNEL', 'twilio')),
    ],

    'seed' => [
        // Vía config y no env() directo: con config:cache, env() devuelve null
        'manager_phone' => env('SEED_MANAGER_PHONE'),
    ],

];
