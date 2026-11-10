<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use PHPUnit\Framework\TestCase;

/**
 * M-53: o modelo de .env do roteiro (docs/deploy-hostinger.md, entre os marcadores modelo-env) traz TODAS as chaves
 * do backend/.env.example, cada uma com um comentário dizendo de onde vem o valor, e nenhum segredo.
 */
class EnvModelDocTest extends TestCase
{
    private const SECRET_KEYS = [
        'DB_PASSWORD', 'MAIL_PASSWORD', 'MERCADOPAGO_ACCESS_TOKEN', 'MERCADOPAGO_WEBHOOK_SECRET',
        'MELHOR_ENVIO_TOKEN', 'ADMIN_PASSWORD', 'AWS_SECRET_ACCESS_KEY', 'APP_KEY',
    ];

    public function test_modelo_tem_todas_as_chaves_do_env_example(): void
    {
        $missing = array_diff($this->keys($this->read('backend/.env.example')), array_keys($this->model()));

        $this->assertSame([], array_values($missing), 'Chaves do backend/.env.example que faltam no modelo do roteiro');
    }

    public function test_cada_chave_do_modelo_diz_de_onde_vem_o_valor(): void
    {
        foreach ($this->model() as $key => [, $comment]) {
            $this->assertNotSame('', $comment, "{$key} sem comentário de origem no modelo");
        }
    }

    public function test_modelo_nao_tem_segredo(): void
    {
        $model = $this->model();

        foreach (self::SECRET_KEYS as $key) {
            $this->assertArrayHasKey($key, $model);
            [$value] = $model[$key];
            $this->assertMatchesRegularExpression('/^(|<[^>]+>)$/', $value, "{$key} precisa ficar vazio ou com <marcador> no modelo");
        }

        foreach ($model as $key => [$value]) {
            $this->assertDoesNotMatchRegularExpression('/^(APP_USR|TEST)-|^base64:|^re_[A-Za-z0-9]/', $value, "{$key} parece ter um valor real");
        }
    }

    public function test_env_example_tem_o_reply_to(): void
    {
        $keys = $this->keys($this->read('backend/.env.example'));

        $this->assertContains('MAIL_REPLY_TO_ADDRESS', $keys);
        $this->assertContains('MAIL_REPLY_TO_NAME', $keys);
    }

    /** @return array<string, array{0: string, 1: string}> chave => [valor, comentário] */
    private function model(): array
    {
        $doc = $this->read('docs/deploy-hostinger.md');
        $this->assertSame(1, preg_match('/<!-- modelo-env:inicio -->\s*```dotenv\n(.*?)```\s*<!-- modelo-env:fim -->/s', $doc, $block), 'Bloco do modelo de .env não encontrado no roteiro');

        $model = [];
        foreach (explode("\n", $block[1]) as $line) {
            if (preg_match('/^([A-Z][A-Z0-9_]*)=("[^"]*"|<[^>]*>|\S*)\s*(?:#\s*(.*))?$/', $line, $m) === 1) {
                $this->assertArrayNotHasKey($m[1], $model, "{$m[1]} repetida no modelo");
                $model[$m[1]] = [trim($m[2], '"'), trim($m[3] ?? '')];
            }
        }

        return $model;
    }

    /** @return list<string> */
    private function keys(string $content): array
    {
        preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', $content, $m);

        return $m[1];
    }

    private function read(string $path): string
    {
        $content = file_get_contents(dirname(__DIR__, 4).'/'.$path);
        $this->assertIsString($content);

        return $content;
    }
}
