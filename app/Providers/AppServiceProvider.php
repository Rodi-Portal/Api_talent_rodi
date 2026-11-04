<?php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use App\Models\Auth\PersonalAccessToken;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Reservar las credenciales de renovacion exclusivamente
        // para el futuro endpoint de refresh de MiPortal.
        Sanctum::authenticateAccessTokensUsing(
            static function ($token, bool $valid): bool {
                return $valid
                    && $token->name !== 'miportal_refresh'
                    && ! in_array(
                        'miportal:refresh',
                        $token->abilities ?? [],
                        true
                    );
            }
        );
    }
}
