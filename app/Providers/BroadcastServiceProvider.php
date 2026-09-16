<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Expose auth endpoint at /api/broadcasting/auth for SPA authorizers.
        Broadcast::routes([
            'prefix' => 'api',
            'middleware' => ['auth:sanctum'],
        ]);

        require base_path('routes/channels.php');
    }
}
