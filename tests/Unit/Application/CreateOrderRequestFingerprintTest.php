<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\DTOs\CreateOrderDTO;
use App\Application\Idempotency\CreateOrderRequestFingerprint;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CreateOrderRequestFingerprintTest extends TestCase
{
    #[Test]
    public function item_order_is_not_incidental_to_the_business_fingerprint(): void
    {
        $left = new CreateOrderDTO(7, [
            ['product_id' => 2, 'quantity' => 1],
            ['product_id' => 1, 'quantity' => 3],
        ], 'key-a');
        $right = new CreateOrderDTO(7, [
            ['product_id' => 1, 'quantity' => 3],
            ['product_id' => 2, 'quantity' => 1],
        ], 'different-transport-key');

        $this->assertSame(
            CreateOrderRequestFingerprint::fromDTO($left),
            CreateOrderRequestFingerprint::fromDTO($right)
        );
    }

    #[Test]
    public function changed_business_parameters_change_the_fingerprint(): void
    {
        $original = new CreateOrderDTO(7, [
            ['product_id' => 1, 'quantity' => 1],
        ], 'same-key');
        $changed = new CreateOrderDTO(7, [
            ['product_id' => 1, 'quantity' => 2],
        ], 'same-key');

        $this->assertNotSame(
            CreateOrderRequestFingerprint::fromDTO($original),
            CreateOrderRequestFingerprint::fromDTO($changed)
        );
    }
}
