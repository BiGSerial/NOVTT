<?php

declare(strict_types=1);

namespace Tests\Feature\Contracts;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Every /api/v1 route is documented in contracts/http/openapi.v1.yaml and vice versa. */
final class OpenApiRoutesTest extends TestCase
{
    public function test_routes_and_openapi_paths_match(): void
    {
        $yaml = (string) file_get_contents(config('novtt.contracts_path') . '/http/openapi.v1.yaml');
        $documented = [];
        $path = null;
        foreach (explode("\n", $yaml) as $line) {
            if (preg_match('#^  (/[^:]+):\s*$#', $line, $m) === 1) {
                $path = $m[1];
            } elseif ($path !== null && preg_match('#^    (get|post|put|patch|delete):#', $line, $m) === 1) {
                $documented[] = strtoupper($m[1]) . ' ' . preg_replace('#\{[^}]+\}#', '{}', $path);
            } elseif (preg_match('#^\S#', $line) === 1) {
                $path = null;
            }
        }

        $registered = [];
        foreach (Route::getRoutes() as $route) {
            if (!str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $registered[] = $method . ' ' . preg_replace('#\{[^}]+\}#', '{}', substr($route->uri(), strlen('api/v1')));
            }
        }
        sort($documented);
        sort($registered);

        $this->assertSame([], array_values(array_diff($registered, $documented)), 'routes missing from OpenAPI');
        $this->assertSame([], array_values(array_diff($documented, $registered)), 'documented paths without a route');
    }
}
