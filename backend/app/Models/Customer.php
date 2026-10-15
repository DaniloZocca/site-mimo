<?php

declare(strict_types=1);

namespace App\Models;

use App\Mail\CustomerResetPasswordMail;
use App\Mail\CustomerVerifyEmailMail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Conta de cliente da loja (M-26). Não tem acesso ao painel: o Filament usa o guard `web` e a tabela `users`.
 */
class Customer extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, HasVersion4Uuids;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * E-mail sempre em minúsculas e sem espaços nas pontas: o UNIQUE vale para qualquer caixa
     * e "Meus pedidos" compara com lower(customer_email).
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => self::normalizeEmail($value),
        );
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->queueMail(new CustomerVerifyEmailMail($this));
    }

    /**
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->queueMail(new CustomerResetPasswordMail($this, (string) $token));
    }

    /**
     * Falha ao enfileirar o e-mail não derruba a resposta. O log leva só as classes
     * (sem e-mail, token nem a mensagem da exceção, que pode conter o endereço).
     */
    private function queueMail(Mailable $mail): void
    {
        try {
            Mail::to($this->email)->queue($mail);
        } catch (Throwable $e) {
            Log::error('Falha ao enfileirar e-mail de conta de cliente.', [
                'mail' => $mail::class,
                'exception' => $e::class,
            ]);
        }
    }
}
