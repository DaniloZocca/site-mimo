<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Validation;

use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\BuildsCheckoutPayload;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * Mensagens padrão do validador (regras sem mensagem própria no FormRequest) em pt-BR,
 * vindas de lang/pt_BR (M-20), com nomes amigáveis no lugar do nome técnico do campo.
 */
#[Group('characterization')]
class ValidationMessagesPtBrTest extends CharacterizationTestCase
{
    use BuildsCheckoutPayload;

    public function test_locale_padrao_do_config_e_pt_br(): void
    {
        $config = require base_path('config/app.php');

        // Sem APP_LOCALE no ambiente, o padrão do config vale; com ele, o .env decide.
        $this->assertSame(env('APP_LOCALE', 'pt_BR'), $config['locale']);
    }

    public function test_regra_sem_mensagem_propria_responde_em_portugues_com_nome_amigavel(): void
    {
        ['product' => $product] = $this->catalogItem();

        $this->postJson('/api/shipping/calculate', [
            'destination_cep' => '01001-000',
            'items' => [['product_id' => $product->id]],
        ])
            ->assertStatus(422)
            ->assertExactJson([
                'message' => 'O campo quantidade é obrigatório.',
                'errors' => ['items.0.quantity' => ['O campo quantidade é obrigatório.']],
            ]);
    }

    public function test_resumo_de_varios_erros_fica_em_portugues(): void
    {
        ['product' => $product, 'color' => $color] = $this->catalogItem();
        $payload = $this->checkoutPayload([$this->itemPayload($product, $color, null, 1, 'resina')], 'pix', self::SERVER_PAC_CENTS, [
            'shipping' => ['street' => str_repeat('a', 151)],
        ]);

        $response = $this->postJson('/api/checkout', $payload)->assertStatus(422);

        $this->assertSame('O valor escolhido em material é inválido.', $response->json('errors')['items.0.material'][0]);
        $this->assertSame('O campo rua não pode ter mais de 150 caracteres.', $response->json('errors')['shipping.street'][0]);
        $this->assertSame('O campo rua não pode ter mais de 150 caracteres. (e mais 1 erro)', $response->json('message'));
    }

    public function test_required_if_usa_nome_amigavel_do_valor(): void
    {
        ['product' => $product, 'color' => $color] = $this->catalogItem();
        $payload = $this->checkoutPayload([$this->itemPayload($product, $color)], 'credit_card');
        unset($payload['payment']['installments']);

        $response = $this->postJson('/api/checkout', $payload)->assertStatus(422);

        $this->assertSame(
            'O campo parcelas é obrigatório quando forma de pagamento for cartão de crédito.',
            $response->json('errors')['payment.installments'][0]
        );
    }

    public function test_mensagem_propria_do_form_request_continua_valendo(): void
    {
        $this->postJson('/api/shipping/calculate', ['items' => [['product_id' => 'x', 'quantity' => 1]]])
            ->assertStatus(422)
            ->assertJsonPath('errors.destination_cep.0', 'O CEP de destino é obrigatório.');
    }
}
