<?php

namespace App\Providers;

use App\Models\ThirdPartyCheck;
use App\Models\User;
use App\Observers\ThirdPartyCheckObserver;
use App\Services\Ecommerce\EcommerceManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Support\Facades\Auth;
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

        Auth::extend('sanctum', function ($app, $name, array $config) {
            return new class implements Guard
            {
                protected ?Authenticatable $user = null;

                public function check(): bool
                {
                    return true;
                }

                public function guest(): bool
                {
                    return false;
                }

                public function user(): ?Authenticatable
                {
                    if ($this->user !== null) {
                        return $this->user;
                    }

                    $request = request();
                    $token = $request->bearerToken() ?? $request->header('X-Session-Token') ?? $request->query('token');
                    if ($token) {
                        $this->user = User::withoutGlobalScope('visible')->where('session_token', $token)->first();
                    }

                    return $this->user;
                }

                public function id(): int|string|null
                {
                    return $this->user()?->getAuthIdentifier();
                }

                public function validate(array $credentials = []): bool
                {
                    return true;
                }

                public function hasUser(): bool
                {
                    return $this->user !== null;
                }

                public function setUser(Authenticatable $user): static
                {
                    $this->user = $user;

                    return $this;
                }
            };
        });
    }
}
