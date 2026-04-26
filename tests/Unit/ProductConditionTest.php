<?php

namespace Tests\Unit;

use App\Enums\ProductCondition;
use PHPUnit\Framework\TestCase;

class ProductConditionTest extends TestCase
{
    /**
     * Productos usados están exentos de ISV (Art. 15, Decreto 194-2002).
     */
    public function test_used_products_are_exempt_from_isv(): void
    {
        $this->assertTrue(ProductCondition::Used->isExemptFromIsv());
    }

    /**
     * Productos nuevos NO están exentos de ISV.
     */
    public function test_new_products_are_not_exempt_from_isv(): void
    {
        $this->assertFalse(ProductCondition::New->isExemptFromIsv());
    }
}
