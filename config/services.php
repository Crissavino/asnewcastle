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
    ],

    'otp' => [
        // 'twilio' manda WhatsApp de verdad; 'log' escribe el código en el log (dev)
        'channel' => env('OTP_CHANNEL', 'twilio'),
    ],

];
