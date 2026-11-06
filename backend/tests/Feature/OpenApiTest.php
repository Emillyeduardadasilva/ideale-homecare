<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class OpenApiTest extends TestCase
{
    public function test_openapi_covers_every_registered_api_operation_and_every_reference_resolves(): void
    {
        $document = $this->getJson('/api/v1/openapi.json')->assertOk()->assertJsonPath('openapi', '3.1.0')->json();
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/') && $route->uri() !== 'sanctum/csrf-cookie') {
                continue;
            }
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $operation = $document['paths']['/'.$route->uri()][strtolower($method)] ?? null;
                $this->assertNotNull($operation, "Operação não documentada: $method /{$route->uri()}");
                $this->assertNotEmpty($operation['responses']);
            }
        }
        $this->assertReferencesResolve($document, $document);
    }

    private function assertReferencesResolve(array $node, array $document): void
    {
        if (isset($node['$ref'])) {
            $this->assertStringStartsWith('#/', $node['$ref']);
            $value = $document;
            foreach (explode('/', substr($node['$ref'], 2)) as $part) {
                $this->assertIsArray($value);
                $this->assertArrayHasKey($part, $value);
                $value = $value[$part];
            }
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                $this->assertReferencesResolve($value, $document);
            }
        }
    }
}
