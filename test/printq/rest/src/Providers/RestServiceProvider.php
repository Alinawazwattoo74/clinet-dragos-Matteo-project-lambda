<?php

namespace Printq\Rest\Providers;

use Printq\Rest\BaseServiceProvider;

class RestServiceProvider extends BaseServiceProvider
{
    /**
     * Register bindings in the container.
     *
     * @return void
     */
    public function register()
    {
        $this->registerConfig();
        $this->registerRoutes();
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }

    protected function getModuleDir()
    {
        $class_info = new \ReflectionClass(get_class($this));
        return dirname($class_info->getFileName());
    }

    protected function registerConfig()
    {
        $configPath = $this->getModuleDir().'/../Config/config.php';
        if (file_exists($configPath)) {
            $this->mergeConfigFrom(
                $configPath, 'rest'
            );
        }
    }

    protected function registerRoutes()
    {
        $this->app->router->group([
            'namespace' => 'Printq\Rest\Http\Controllers',
        ], function ($router) {
            require $this->getModuleDir().'/../Routes/web.php';
        });
    }
}