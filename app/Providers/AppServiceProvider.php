<?php

namespace App\Providers;

use App\Enums\ApiName;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureUrl();
        $this->registerHttpMacros();
        $this->configureCommand();

    }

    private function configureUrl(): void
    {
        if(config('app.env') === 'production') {
            URL::forceScheme('https');
        }
    }

    private function registerHttpMacros(): void
    {
        try {
            Http::macro('job', function () {
                $apiKey = DB::table('api_keys')
                    ->where('api_name', '=', ApiName::JOB->value)
                    ->orderByDesc('request_remaining')
                    ->first();
                logger('API Key for request', [
                    'API Key' => $apiKey->api_key,
                    'Request Remaining' => $apiKey->request_remaining
                ]);
                return Http::withHeaders([
                    'X-RapidAPI-Host' => 'jsearch.p.rapidapi.com',
                    'X-RapidAPI-Key' => $apiKey->api_key,
                ])->baseUrl('https://jsearch.p.rapidapi.com');
            });

            Http::macro('openai', function () {
                $apiKey = config('ai.chat_gpt_api_key');

                return Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $apiKey,
                ])->baseUrl('https://api.openai.com/v1/chat');
            });


        } catch (\Exception $e){
            logger('Error on app service provider', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);
        }
    }

    private function configureCommand(): void
    {
        DB::prohibitDestructiveCommands($this->app->isProduction());
    }


}
