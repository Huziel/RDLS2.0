<?php

namespace Tests\Feature;

use Tests\TestCase;

class UnknownApiRouteTest extends TestCase
{
    public function test_unknown_api_routes_return_json_404_instead_of_the_spa(): void
    {
        // FASE 6B: cobertura GET y POST sobre /api/*, raiz /api y trailing slash.
        $uris = [
            '/api/v1/not-a-real-endpoint',
            '/api/not-a-real-endpoint',
            '/api',
            '/api/',
        ];

        foreach ($uris as $uri) {
            foreach (['get', 'post'] as $method) {
                $response = $this->{$method}($uri);

                $response->assertNotFound()->assertHeader('content-type', 'application/json');
                $this->assertJson($response->getContent());
                $this->assertStringNotContainsString('<!DOCTYPE html>', $response->getContent());
            }
        }
    }

    public function test_deep_spa_routes_keep_serving_the_bundle(): void
    {
        foreach (['/dashboard/orders', '/delivery/profile', '/tienda-publica/profunda/ruta'] as $uri) {
            $response = $this->get($uri);

            $response->assertOk();
            $this->assertStringContainsString('<!DOCTYPE html>', $response->getContent());
            $this->assertStringNotContainsString('"message"', $response->getContent());
        }
    }
}
