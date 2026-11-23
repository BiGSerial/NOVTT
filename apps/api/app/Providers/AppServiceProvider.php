<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        // Fail loudly on lazy loading (N+1), on attributes outside #[Fillable] (mass assignment)
        // and on reading attributes that were not selected.
        Model::shouldBeStrict();
    }
}
