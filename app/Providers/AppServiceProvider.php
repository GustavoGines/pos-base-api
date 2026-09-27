<?php

namespace App\Providers;

use App\Models\ThirdPartyCheck;
use App\Observers\ThirdPartyCheckObserver;
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
        ThirdPartyCheck::observe(ThirdPartyCheckObserver::class);
    }
}
