<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * E-mail e senha do administrador vêm só do ambiente (ADMIN_EMAIL / ADMIN_PASSWORD).
     * Sem padrão no código: se faltarem, o seeder falha em vez de criar um admin conhecido.
     */
    public function run(): void
    {
        $adminEmail = env('ADMIN_EMAIL');
        $adminPassword = env('ADMIN_PASSWORD');

        if (empty($adminEmail) || empty($adminPassword)) {
            throw new \RuntimeException('Defina ADMIN_EMAIL e ADMIN_PASSWORD no .env para criar o admin.');
        }

        User::updateOrCreate(
            ['email' => $adminEmail],
            [
                'name' => 'Administrador MIMOO LAB',
                'password' => Hash::make($adminPassword),
                'is_admin' => true,
                'email_verified_at' => now(),
            ]
        );
    }
}
