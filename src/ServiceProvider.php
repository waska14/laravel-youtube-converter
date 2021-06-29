<?php

namespace Waska\LaravelYoutubeConverter;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function boot()
    {
        $this->publishConfig();
    }

    public function register()
    {
        $this->registerConfig();
        parent::register();
        $this->registerSingletons();
    }

    /**
     * This method registers singletons
     */
    protected function registerSingletons()
    {
    }

    /**
     * This method registers default config if it is not published.
     */
    protected function registerConfig()
    {
        if (is_null(config('laravel-youtube-converter')) && file_exists($this->getConfigPath())) {
            $this->mergeConfigFrom($this->getConfigPath(), 'laravel-youtube-converter');
        }
    }

    /**
     * This method returns config path
     *
     * @return string
     */
    protected function getConfigPath(): string
    {
        return __DIR__ . '/../config/laravel-youtube-converter.php';
    }

    /**
     * This method publishes config. [Command: php artisan vendor:publish --tag=waska-laravel-youtube-converter]
     */
    protected function publishConfig()
    {
        $this->publishes([
            $this->getConfigPath() => config_path('laravel-youtube-converter.php'),
        ], 'waska-laravel-youtube-converter');
    }
}
