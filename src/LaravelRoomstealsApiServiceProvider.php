<?php

namespace NateRitter\LaravelRoomstealsApi;

use Illuminate\Support\ServiceProvider;

class LaravelRoomstealsApiServiceProvider extends ServiceProvider
{
    /**
     * Perform post-registration booting of services.
     *
     * @return void
     */
    public function boot()
    {
        // $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'nateritter');
        // $this->loadViewsFrom(__DIR__.'/../resources/views', 'nateritter');
        // $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        // $this->loadRoutesFrom(__DIR__.'/routes.php');

        // Publishing is only necessary when using the CLI.
        if ($this->app->runningInConsole()) {
            $this->bootForConsole();
        }
    }

    /**
     * Register any package services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laravelroomstealsapi.php', 'laravelroomstealsapi');

        // Register the service the package provides.
        $this->app->singleton('laravelroomstealsapi', function ($app) {
            return new LaravelRoomstealsApi();
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return ['laravelroomstealsapi'];
    }

    /**
     * Console-specific booting.
     *
     * @return void
     */
    protected function bootForConsole()
    {
        // Publishing the configuration file.
        $this->publishes([
            __DIR__.'/../config/laravelroomstealsapi.php' => config_path('laravelroomstealsapi.php'),
        ], 'laravelroomstealsapi.config');

        // Publishing the views.
        /*$this->publishes([
            __DIR__.'/../resources/views' => base_path('resources/views/vendor/nateritter'),
        ], 'laravelroomstealsapi.views');*/

        // Publishing assets.
        /*$this->publishes([
            __DIR__.'/../resources/assets' => public_path('vendor/nateritter'),
        ], 'laravelroomstealsapi.views');*/

        // Publishing the translation files.
        /*$this->publishes([
            __DIR__.'/../resources/lang' => resource_path('lang/vendor/nateritter'),
        ], 'laravelroomstealsapi.views');*/

        // Registering package commands.
        // $this->commands([]);
    }
}
