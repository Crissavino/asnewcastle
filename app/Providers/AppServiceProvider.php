<?php

namespace App\Providers;

use App\Services\Otp\LogOtpChannel;
use App\Services\Otp\OtpChannel;
use App\Services\Otp\TwilioOtpChannel;
use App\Services\Stripe\RealStripeGateway;
use App\Services\Stripe\StripeGateway;
use App\Services\Translation\AzureTranslator;
use App\Services\Translation\NullTranslator;
use App\Services\Translation\Translator;
use Stripe\StripeClient;
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

        $this->app->bind(StripeGateway::class, function () {
            return new RealStripeGateway(new StripeClient(config('services.stripe.secret')));
        });

        $this->app->bind(Translator::class, function () {
            if (config('services.translator.driver') === 'azure') {
                return new AzureTranslator(
                    (string) config('services.translator.key'),
                    (string) config('services.translator.region'),
                    (string) config('services.translator.endpoint'),
                );
            }

            return new NullTranslator();
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
