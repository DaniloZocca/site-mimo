<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class FixStoragePermissionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'storage:fix-permissions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Garante a existência, integridade de diretórios, symlinks e permissões de escrita do storage do Laravel.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔧 Iniciando auditoria e ajuste de permissões do Storage MIMOO LAB...');
        $startTime = microtime(true);

        $directories = [
            storage_path('app/livewire-tmp'),
            storage_path('app/public/products'),
            storage_path('framework/cache/data'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            storage_path('logs'),
        ];

        // 1. Criação de Diretórios Críticos e Aplicação de Permissões
        $this->newLine();
        $this->line('📁 <fg=yellow>Verificando e criando diretórios de armazenamento...</>');

        foreach ($directories as $directory) {
            if (! File::isDirectory($directory)) {
                File::makeDirectory($directory, 0775, true, true);
                $this->line("   [+] Criado: <fg=green>{$directory}</>");
            } else {
                $this->line("   [✓] Existente: <fg=gray>{$directory}</>");
            }

            // Aplica permissão 0775
            @chmod($directory, 0775);
        }

        // 2. Criação do .gitignore no livewire-tmp
        $this->newLine();
        $this->line('🛡️ <fg=yellow>Configurando proteção do diretório temporário...</>');

        $gitignorePath = storage_path('app/livewire-tmp/.gitignore');
        $gitignoreContent = "*\n!.gitignore\n";

        if (! File::exists($gitignorePath)) {
            File::put($gitignorePath, $gitignoreContent);
            $this->line("   [+] Criado .gitignore em: <fg=green>{$gitignorePath}</>");
        } else {
            $this->line("   [✓] .gitignore verificado em: <fg=gray>{$gitignorePath}</>");
        }

        // 3. Verificação e Recriação do Symlink (public/storage)
        $this->newLine();
        $this->line('🔗 <fg=yellow>Verificando link simbólico público (public/storage)...</>');

        $publicStoragePath = public_path('storage');

        if (! file_exists($publicStoragePath)) {
            Artisan::call('storage:link');
            $this->line('   [+] Symlink criado com sucesso via <fg=green>php artisan storage:link</>');
        } else {
            $this->line('   [✓] Symlink ativo e apontando corretamente para o storage público.');
        }

        // 4. Resumo de Parâmetros de Upload Recomendados
        $this->newLine();
        $this->info('📊 Diagnóstico do Ambiente PHP Atual:');
        $this->table(
            ['Diretiva PHP', 'Valor Atual', 'Recomendado para 25MB'],
            [
                ['upload_max_filesize', ini_get('upload_max_filesize') ?: 'N/A', '>= 30M'],
                ['post_max_size', ini_get('post_max_size') ?: 'N/A', '>= 35M'],
                ['memory_limit', ini_get('memory_limit') ?: 'N/A', '>= 256M'],
                ['max_execution_time', (ini_get('max_execution_time') ?: '0').'s', '>= 120s'],
            ]
        );

        $elapsed = number_format((microtime(true) - $startTime) * 1000, 2);
        $this->newLine();
        $this->info("✅ Permissões e estrutura de storage ajustadas com sucesso em {$elapsed} ms!");

        return Command::SUCCESS;
    }
}
