<?php

declare(strict_types=1);

namespace Tests\Feature\KnownBugs\Checkout;

use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\BuildsCheckoutPayload;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * Comportamento CORRETO esperado do checkout. Estes testes FALHAM hoje.
 */
#[Group('known-bugs')]
class CheckoutKnownBugsTest extends CharacterizationTestCase
{
    use BuildsCheckoutPayload;

    /**
     * P2 — gêmeo de CheckoutIdempotencyAndRateLimitTest::test_contador_e_compartilhado_com_rastreio_e_frete_por_ip
     */
    public function test_rate_limit_do_checkout_e_independente_do_rastreio(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->postJson('/api/orders/track', ['order_number' => 'MIMOO-XXXXXXXX', 'email' => 'a@b.com']);
        }

        $this->postJson('/api/checkout', [])->assertStatus(422);
    }
}
