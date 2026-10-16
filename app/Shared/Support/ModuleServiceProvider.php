<?php

declare(strict_types=1);

namespace App\Shared\Support;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;

abstract class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $config = $this->modulePath().'/config.php';

        if (is_file($config)) {
            $this->mergeConfigFrom($config, $this->configKey());
        }
    }

    public function boot(): void
    {
        $path = $this->modulePath();

        $routes = $path.'/routes/api.php';

        if (is_file($routes)) {
            Route::prefix('api')->middleware('api')->group($routes);
        }

        $migrations = $path.'/Database/Migrations';

        if (is_dir($migrations)) {
            $this->loadMigrationsFrom($migrations);
        }

        $commands = $this->consoleCommands();

        if ($commands !== []) {
            $this->commands($commands);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $this->schedule($schedule);
        });

        foreach ($this->listeners() as $event => $listeners) {
            foreach ($listeners as $listener) {
                Event::listen($event, $listener);
            }
        }
    }

    /**
     * Artisan commands of the module. (Named consoleCommands() because
     * ServiceProvider::commands($commands) already exists with another signature.)
     *
     * @return array<int, class-string>
     */
    protected function consoleCommands(): array
    {
        return [];
    }

    protected function schedule(Schedule $schedule): void {}

    /**
     * @return array<class-string, list<class-string>>
     */
    protected function listeners(): array
    {
        return [];
    }

    /** Config key: lowercased class name without the "ServiceProvider" suffix. */
    protected function configKey(): string
    {
        return strtolower(substr(class_basename($this), 0, -strlen('ServiceProvider')));
    }

    protected function modulePath(): string
    {
        $file = (new ReflectionClass($this))->getFileName();

        return dirname($file === false ? '' : $file);
    }
}
