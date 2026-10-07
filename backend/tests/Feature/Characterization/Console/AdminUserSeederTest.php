<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Console;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * O admin inicial vem só de ADMIN_EMAIL / ADMIN_PASSWORD: sem padrão no código-fonte.
 */
#[Group('characterization')]
class AdminUserSeederTest extends CharacterizationTestCase
{
    private const VARS = ['ADMIN_EMAIL', 'ADMIN_PASSWORD'];

    private array $original = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::VARS as $name) {
            $this->original[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->original as $name => [$env, $server, $putenv]) {
            $this->setEnv($name, null);
            if ($env !== null) {
                $_ENV[$name] = $env;
            }
            if ($server !== null) {
                $_SERVER[$name] = $server;
            }
            if ($putenv !== false) {
                putenv("{$name}={$putenv}");
            }
        }

        parent::tearDown();
    }

    public function test_sem_variaveis_de_ambiente_o_seeder_falha_e_nao_cria_admin(): void
    {
        $this->setEnv('ADMIN_EMAIL', null);
        $this->setEnv('ADMIN_PASSWORD', null);

        try {
            $this->seed(AdminUserSeeder::class);
            $this->fail('O seeder deveria falhar sem ADMIN_EMAIL e ADMIN_PASSWORD.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ADMIN_EMAIL', $e->getMessage());
        }

        $this->assertSame(0, User::count());
    }

    public function test_so_com_a_senha_faltando_o_seeder_falha(): void
    {
        $this->setEnv('ADMIN_EMAIL', 'admin@exemplo.test');
        $this->setEnv('ADMIN_PASSWORD', null);

        $this->expectException(\RuntimeException::class);

        $this->seed(AdminUserSeeder::class);
    }

    public function test_com_variaveis_de_ambiente_cria_o_admin_com_senha_em_hash(): void
    {
        $this->setEnv('ADMIN_EMAIL', 'admin@exemplo.test');
        $this->setEnv('ADMIN_PASSWORD', 'senha-de-teste-123');

        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', 'admin@exemplo.test')->sole();
        $this->assertTrue($admin->is_admin);
        $this->assertNotSame('senha-de-teste-123', $admin->password);
        $this->assertTrue(Hash::check('senha-de-teste-123', $admin->password));
    }

    private function setEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        } else {
            $_ENV[$name] = $_SERVER[$name] = $value;
            putenv("{$name}={$value}");
        }

        // env() guarda o repositório de variáveis; recriar garante a leitura do valor novo.
        $property = new \ReflectionProperty(Env::class, 'repository');
        $property->setValue(null, null);
    }
}
