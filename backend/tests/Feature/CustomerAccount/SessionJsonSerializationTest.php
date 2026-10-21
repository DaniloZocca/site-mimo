<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerAccount;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-35: a sessão é gravada em JSON (`session.serialization`), não com serialize() do PHP.
 * Driver `database`, como em produção, para a sessão ir mesmo para o banco entre as requisições.
 */
class SessionJsonSerializationTest extends CharacterizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'database']);
    }

    public function test_config_usa_json(): void
    {
        $this->assertSame('json', config('session.serialization'));
    }

    public function test_login_do_cliente_grava_sessao_em_json_e_continua_valendo(): void
    {
        $customer = Customer::factory()->create(['email' => 'ana@exemplo.com', 'password' => 'gatinho123']);

        $this->postJson('/api/conta/login', ['email' => 'ana@exemplo.com', 'password' => 'gatinho123'])->assertOk();

        $payload = base64_decode((string) DB::table('sessions')->sole()->payload, true);
        $this->assertIsString($payload);
        $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);
        $this->assertSame($customer->id, $data[auth('customer')->getName()] ?? null);

        $this->getJson('/api/conta/me')->assertOk()->assertJsonPath('customer.email', 'ana@exemplo.com');
    }
}
