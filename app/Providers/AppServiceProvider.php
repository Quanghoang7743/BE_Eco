<?php

namespace App\Providers;

use App\Models\Bank;
use App\Models\BankAccount;
use App\Observers\BankObserver;
use App\Observers\BankAccountObserver;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton('db.schema', function ($app) {
            $connection = $app['db']->connection();
            if (method_exists($connection, 'useDefaultSchemaGrammar') && is_null($connection->getSchemaGrammar())) {
                $connection->useDefaultSchemaGrammar();
            }
            if ($connection->getDriverName() !== 'mysql') {
                return $connection->getSchemaBuilder();
            }

            return new \App\Database\Schema\CachingSchemaBuilder($connection);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (app()->isLocal()) {
            $logAllQueries = (bool) config('logging.sql_queries', false);
            $slowQueryMs = (float) config('logging.slow_query_ms', 0);

            if ($logAllQueries || $slowQueryMs > 0) {
                DB::listen(function ($query) use ($logAllQueries, $slowQueryMs) {
                    if (! $logAllQueries && $query->time < $slowQueryMs) {
                        return;
                    }

                    $bindings = array_map(static function ($binding) {
                        if ($binding instanceof \DateTimeInterface) {
                            return "'" . $binding->format('Y-m-d H:i:s') . "'";
                        }

                        if (is_bool($binding)) {
                            return $binding ? '1' : '0';
                        }

                        if ($binding === null) {
                            return 'null';
                        }

                        if (is_numeric($binding)) {
                            return (string) $binding;
                        }

                        return "'" . str_replace("'", "''", (string) $binding) . "'";
                    }, $query->bindings);

                    $sql = Str::replaceArray('?', $bindings, $query->sql);

                    Log::info('SQL', [
                        'sql' => $sql,
                        'time_ms' => $query->time,
                    ]);
                });
            }

            if ((bool) config('logging.api_requests', false)) {
                Event::listen(RequestHandled::class, function ($event) {
                    Log::info('API', [
                        'method' => $event->request->method(),
                        'url' => $event->request->fullUrl(),
                        'status' => $event->response->getStatusCode(),
                        'duration_ms' => defined('LARAVEL_START')
                            ? round((microtime(true) - LARAVEL_START) * 1000, 2)
                            : null,
                    ]);
                });
            }
        }

        Paginator::useBootstrapFive();

        Bank::observe(BankObserver::class);
        BankAccount::observe(BankAccountObserver::class);
    }
}
