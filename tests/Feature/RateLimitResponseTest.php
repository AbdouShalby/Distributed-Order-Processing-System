<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RateLimitResponseTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function exceeding_the_public_limit_returns_structured_429_with_debug_off(): void
    {
        config(['app.debug' => false]);

        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/products')->assertOk();
        }

        $this->getJson('/api/products')
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'RATE_LIMIT_EXCEEDED')
            ->assertHeader('Retry-After');
    }
}
