<?php

namespace App\Providers;

use App\Services\Otp\LogOtpChannel;
use App\Services\Otp\OtpChannel;
use App\Services\Otp\TwilioOtpChannel;
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
                return new TwilioOtpChannel(new Client(
                    config('services.twilio.sid'),
                    config('services.twilio.token'),
                ));
            }

            return new LogOtpChannel();
        });
    }

    public function boot(): void
    {
        //
    }
}
