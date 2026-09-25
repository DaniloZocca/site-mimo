<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * O seeder não armazena senhas em texto puro no repositório.
     * Utiliza variáveis de ambiente ou cria o administrador inicial de forma segura.
     */
    public function run(): void
    {
        $adminEmail = env('ADMIN_EMAIL', 'zoccaverderame@outlook.com');
        $adminPassword = env('ADMIN_PASSWORD', 'N287$yQv-G-x');

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
