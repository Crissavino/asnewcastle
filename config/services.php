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

];
