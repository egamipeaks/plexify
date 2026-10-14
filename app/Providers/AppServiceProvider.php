<?php

namespace App\Providers;

use App\Services\Albums\PlexPathMapper;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PlexPathMapper::class, fn () => new PlexPathMapper(
            (string) config('services.plex.path_from'),
            (string) config('services.plex.path_to'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
