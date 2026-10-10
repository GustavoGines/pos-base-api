<?php

namespace App\Providers;

use App\Models\ThirdPartyCheck;
use App\Observers\ThirdPartyCheckObserver;
use App\Services\Ecommerce\EcommerceManager;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(EcommerceManager::class, function () {
            return new EcommerceManager;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ThirdPartyCheck::observe(ThirdPartyCheckObserver::class);
    }
}
