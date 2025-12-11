<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
Broadcast::routes(['middleware' => ['auth:api']]); // <-- api guard (JWT)
require base_path('routes/channels.php');
    }
}
