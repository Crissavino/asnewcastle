<?php

namespace App\Providers;

use App\Services\Otp\LogOtpChannel;
use App\Services\Otp\OtpChannel;
use App\Services\Otp\TwilioOtpChannel;
use App\Services\Stripe\RealStripeGateway;
use App\Services\Stripe\StripeGateway;
use App\Services\Push\FcmPushSender;
use App\Services\Push\NullPushSender;
use App\Services\Push\PushSender;
use App\Services\Translation\AzureTranslator;
use App\Services\Translation\NullTranslator;
use App\Services\Translation\Translator;
use Stripe\StripeClient;
use Mollie\Api\MollieApiClient;
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

        // Cliente Mollie: la key clásica (test_/live_) va por setApiKey; el access
        // token (access_...) por setAccessToken (+ profile_id/testmode en cada llamada).
        $this->app->singleton(MollieApiClient::class, function () {
            $client = new MollieApiClient();
            $key = (string) config('services.mollie.key');

            if ($key !== '') {
                str_starts_with($key, 'access_')
                    ? $client->setAccessToken($key)
                    : $client->setApiKey($key);
            }

            return $client;
        });

        $this->app->bind(PushSender::class, function () {
            if (config('services.push.driver') === 'fcm') {
                return new FcmPushSender(
                    (string) config('services.push.fcm.credentials'),
                    (string) config('services.push.fcm.project_id'),
                );
            }

            return new NullPushSender();
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
