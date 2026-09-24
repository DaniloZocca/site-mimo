<?php

declare(strict_types=1);

namespace Tests\Feature\Characterization\Support;

use MercadoPago\Exceptions\MPApiException;
use MercadoPago\Net\MPHttpClient;
use MercadoPago\Net\MPRequest;
use MercadoPago\Net\MPResponse;
use RuntimeException;
use Throwable;

/**
 * Cliente HTTP falso para o SDK do Mercado Pago (registrado via MercadoPagoConfig::setHttpClient).
 *
 * O SDK usa curl próprio, então Http::fake() não o intercepta. Este cliente:
 * - devolve respostas programadas em ordem (FIFO);
 * - grava todas as requisições para asserções;
 * - lança exceção em requisição não programada (equivalente a preventStrayRequests).
 */
final class FakeMercadoPagoHttpClient implements MPHttpClient
{
    /** @var array<int, MPResponse|Throwable> */
    private array $queue = [];

    /** @var array<int, MPRequest> */
    public array $requests = [];

    /** @var (callable(MPRequest): void)|null */
    private $onSend = null;

    /**
     * Callback executado a cada requisição, antes de devolver a resposta programada
     * (ex.: simular o webhook alterando o pedido enquanto o POST do cartão está em andamento).
     */
    public function onSend(callable $callback): self
    {
        $this->onSend = $callback;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $content
     */
    public function pushJson(array $content, int $status = 201): self
    {
        $this->queue[] = new MPResponse($status, $content);

        return $this;
    }

    /**
     * Resposta de erro da API (o SDK real lança MPApiException para status não-2xx).
     *
     * @param  array<string, mixed>  $content
     */
    public function pushApiError(int $status = 500, array $content = ['message' => 'internal_error']): self
    {
        $this->queue[] = new MPApiException('Api error. Check response for details', new MPResponse($status, $content));

        return $this;
    }

    public function pushException(Throwable $e): self
    {
        $this->queue[] = $e;

        return $this;
    }

    public function send(MPRequest $request): MPResponse
    {
        $this->requests[] = $request;

        if ($this->queue === []) {
            throw new RuntimeException(
                'FakeMercadoPagoHttpClient: requisição não programada para '.$request->getMethod().' '.$request->getUri()
            );
        }

        $next = array_shift($this->queue);

        if ($this->onSend !== null) {
            ($this->onSend)($request);
        }

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $this->echoRequestFields($request, $next);
    }

    /**
     * Como a API real, a criação (POST) devolve external_reference e transaction_amount enviados.
     * Só preenche os campos que a resposta programada omitiu (payment() com esses argumentos null).
     */
    private function echoRequestFields(MPRequest $request, MPResponse $response): MPResponse
    {
        $content = $response->getContent();
        if ($request->getMethod() !== 'POST' || ! is_array($content)) {
            return $response;
        }

        $payload = (array) json_decode((string) $request->getPayload(), true);
        foreach (['external_reference', 'transaction_amount'] as $field) {
            if (! array_key_exists($field, $content) && array_key_exists($field, $payload)) {
                $content[$field] = $payload[$field];
            }
        }

        return new MPResponse($response->getStatusCode(), $content);
    }

    /**
     * @return array<string, mixed>
     */
    public function lastPayload(): array
    {
        $last = end($this->requests);

        return $last ? (array) json_decode((string) $last->getPayload(), true) : [];
    }

    /**
     * Corpo mínimo de um pagamento no formato da API /v1/payments.
     *
     * @return array<string, mixed>
     */
    public static function payment(
        int $id,
        string $status,
        ?string $externalReference = null,
        float|int|string|null $amount = null,
        string $statusDetail = 'accredited',
        ?string $currencyId = 'BRL',
        ?bool $liveMode = true,
    ): array {
        // external_reference/transaction_amount null = omitidos (no POST, ecoados do payload enviado)
        return array_filter([
            'id' => $id,
            'status' => $status,
            'status_detail' => $statusDetail,
            'external_reference' => $externalReference,
            'transaction_amount' => $amount,
            'currency_id' => $currencyId,
            'live_mode' => $liveMode,
        ], fn ($value): bool => $value !== null) + [
            'point_of_interaction' => [
                'transaction_data' => [
                    'qr_code' => '00020126PIXCOPIAECOLA',
                    'qr_code_base64' => 'iVBORw0KGgo=',
                    'ticket_url' => 'https://www.mercadopago.com.br/payments/'.$id.'/ticket',
                ],
            ],
        ];
    }
}
