<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class HealthCheckTest extends TestCase
{
    public function test_health_endpoint_reports_the_application_is_up(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_root_does_not_expose_a_default_page(): void
    {
        $this->get('/')->assertNotFound();
    }

    public function test_unknown_api_route_returns_json_error(): void
    {
        $this->getJson('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }
}
