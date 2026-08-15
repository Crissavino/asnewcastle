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

    'whatsapp' => [
        // Avisos del club (convocatorias, recordatorios, cuotas)
        'channel' => env('WHATSAPP_CHANNEL', env('OTP_CHANNEL', 'twilio')),
    ],

];
