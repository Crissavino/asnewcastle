<?php

namespace App\Providers;

use App\Services\Otp\LogOtpChannel;
use App\Services\Otp\OtpChannel;
use App\Services\Otp\TwilioOtpChannel;
use App\Services\WhatsApp\LogWhatsAppChannel;
use App\Services\WhatsApp\TwilioWhatsAppChannel;
use App\Services\WhatsApp\WhatsAppChannel;
use App\Support\CurrentClub;
use Illuminate\Support\ServiceProvider;
use Twilio\Rest\Client;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentClub::class);

        $this->app->bind(OtpChannel::class, function () {
            if (config('services.otp.channel') === 'twilio') {
                return new TwilioOtpChannel($this->twilioClient());
            }

            return new LogOtpChannel();
        });

        $this->app->bind(WhatsAppChannel::class, function () {
            if (config('services.whatsapp.channel') === 'twilio') {
                return new TwilioWhatsAppChannel($this->twilioClient());
            }

            return new LogWhatsAppChannel();
        });
    }

    public function boot(): void
    {
        //
    }

    protected function twilioClient(): Client
    {
        return new Client(
            config('services.twilio.sid'),
            config('services.twilio.token'),
        );
    }
}
