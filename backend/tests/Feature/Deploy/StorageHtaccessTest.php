<?php

declare(strict_types=1);

namespace Tests\Feature\Deploy;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-80: no servidor, os arquivos de /storage saem direto do LiteSpeed (sem passar pelo Laravel). A regra do
 * `public/.htaccess` só deixa sair foto: um ponto só no caminho, terminando em jpg, jpeg, jfif, png ou webp; o
 * resto dá 403. O teste aplica a mesma expressão do arquivo (o Apache e o LiteSpeed usam PCRE, como o PHP); a
 * prova no servidor de verdade é a conferência do roteiro (1.9).
 */
class StorageHtaccessTest extends CharacterizationTestCase
{
    private const STORAGE_RULE = '/^[ \t]*RewriteCond %\{REQUEST_URI\} \^\/storage\/ \[NC\]\R'
        .'[ \t]*RewriteCond %\{REQUEST_URI\} !(\S+) \[NC\]\R'
        .'[ \t]*RewriteRule \^ - \[F,L\]$/m';

    private function htaccess(): string
    {
        return (string) file_get_contents(public_path('.htaccess'));
    }

    private function isDenied(string $uri): bool
    {
        $this->assertSame(1, preg_match(self::STORAGE_RULE, $this->htaccess(), $match), 'regra do /storage (M-80) não encontrada no public/.htaccess');

        return preg_match('#^/storage/#i', $uri) === 1 && preg_match('#'.$match[1].'#i', $uri) !== 1;
    }

    public function test_regra_fica_depois_da_dos_arquivos_com_ponto_e_antes_do_index_php(): void
    {
        $htaccess = $this->htaccess();
        $this->assertSame(1, preg_match(self::STORAGE_RULE, $htaccess, $match, PREG_OFFSET_CAPTURE));

        $rule = $match[0][1];
        $dotfiles = strpos($htaccess, 'RewriteRule (^|/)\. - [F,L]');
        $frontController = strpos($htaccess, '# Send Requests To Front Controller...');

        $this->assertIsInt($dotfiles);
        $this->assertIsInt($frontController);
        $this->assertGreaterThan($dotfiles, $rule);
        $this->assertLessThan($frontController, $rule);
    }

    /** @return array<string, array{string}> */
    public static function servedUris(): array
    {
        return [
            'foto do produto jpg' => ['/storage/products/01M8DK0VRR7KJCGFS7RWK551X3.jpg'],
            'foto JPG maiúsculo' => ['/storage/products/01M8DK0VRR7KJCGFS7RWK551X3.JPG'],
            'foto jpeg' => ['/storage/products/01M8DK0VRR7KJCGFS7RWK551X3.jpeg'],
            'foto jfif' => ['/storage/products/01M8DK0VRR7KJCGFS7RWK551X3.jfif'],
            'foto png' => ['/storage/products/01M8DK0VRR7KJCGFS7RWK551X3.png'],
            'foto webp' => ['/storage/products/01M8DK0VRR7KJCGFS7RWK551X3.webp'],
            'API' => ['/api/products'],
            'painel' => ['/admin/products/1/edit'],
            'upload do Livewire' => ['/livewire/upload-file'],
            'CSS do Filament' => ['/css/filament/filament/app.css'],
            'JS do Filament' => ['/js/filament/forms/components/file-upload.js'],
            'imagem dos e-mails' => ['/images/email/vaquinha-mimoo.png'],
        ];
    }

    #[DataProvider('servedUris')]
    public function test_fotos_e_o_resto_do_site_continuam_saindo(string $uri): void
    {
        $this->assertFalse($this->isDenied($uri), "{$uri} não pode dar 403");
    }

    /** @return array<string, array{string}> */
    public static function deniedUris(): array
    {
        return [
            'php' => ['/storage/livewire-tmp/5KJimpvyg1vpOCXDYVfvdICNeUQojk-metac2hlbGwucGhw-.php'],
            'PHP maiúsculo' => ['/storage/shell.PHP'],
            'pht' => ['/storage/shell.pht'],
            'phtml' => ['/storage/shell.phtml'],
            'phar' => ['/storage/shell.phar'],
            'html' => ['/storage/products/01M8DK0VRR7KJCGFS7RWK551X3.html'],
            'svg' => ['/storage/desenho.svg'],
            'js' => ['/storage/script.js'],
            'php com caminho extra terminando em .jpg' => ['/storage/shell.php/foto.jpg'],
            'foto com extensão de script no fim' => ['/storage/foto.jpg.php'],
            'dois pontos no nome' => ['/storage/products/foto.final.jpg'],
            'pasta' => ['/storage/products/'],
            'raiz do storage' => ['/storage/'],
        ];
    }

    #[DataProvider('deniedUris')]
    public function test_o_que_nao_e_foto_em_storage_da_403(string $uri): void
    {
        $this->assertTrue($this->isDenied($uri), "{$uri} precisa dar 403");
    }

    public function test_estaticos_saem_com_nosniff_sem_duplicar_o_do_laravel(): void
    {
        $htaccess = $this->htaccess();

        $this->assertSame(1, preg_match('/<IfModule mod_headers\.c>(.*?)<\/IfModule>/s', $htaccess, $headers));
        $this->assertMatchesRegularExpression('/^\s*Header set X-Content-Type-Options "nosniff"$/m', $headers[1]);
        // Com "always" o Apache mandaria o cabeçalho de novo nas respostas do Laravel, que já o enviam.
        $this->assertStringNotContainsString('Header always set X-Content-Type-Options', $htaccess);
    }

    public function test_jfif_sai_como_jpeg(): void
    {
        $this->assertSame(1, preg_match('/<IfModule mod_mime\.c>\s*AddType image\/jpeg \.jfif\s*<\/IfModule>/', $this->htaccess()));
    }

    private function storageHtaccess(): string
    {
        $path = storage_path('app/public/.htaccess');
        $this->assertFileExists($path, 'a terceira camada (storage/app/public/.htaccess) precisa existir');

        return (string) file_get_contents($path);
    }

    private function storageDenies(string $filename): bool
    {
        $this->assertSame(1, preg_match('/<FilesMatch "([^"]+)">\s*Require all denied\s*<\/FilesMatch>/', $this->storageHtaccess(), $match));

        return preg_match('#'.$match[1].'#', $filename) === 1;
    }

    public function test_terceira_camada_fica_no_git_e_nao_liga_o_mod_rewrite_da_pasta(): void
    {
        $this->assertContains('!.htaccess', file(storage_path('app/public/.gitignore'), FILE_IGNORE_NEW_LINES));
        // Um RewriteEngine nesta pasta substituiria as regras do public/.htaccess para tudo que está em /storage.
        $directives = preg_replace('/^\s*#.*$/m', '', $this->storageHtaccess());
        $this->assertStringNotContainsStringIgnoringCase('RewriteEngine', (string) $directives);
    }

    /** @return array<string, array{string}> */
    public static function scriptFilenames(): array
    {
        return [
            'php' => ['shell.php'], 'PHP' => ['shell.PHP'], 'php7' => ['shell.php7'], 'pht' => ['shell.pht'],
            'phtml' => ['shell.phtml'], 'phar' => ['shell.phar'], 'phps' => ['shell.phps'], 'html' => ['pagina.html'],
            'htm' => ['pagina.htm'], 'shtml' => ['pagina.shtml'], 'svg' => ['desenho.svg'], 'js' => ['script.js'],
            'temporário antigo do Livewire' => ['5KJimpvyg1vpOCXDYVfvdICNeUQojk-metac2hlbGwucGhw-.php'],
        ];
    }

    #[DataProvider('scriptFilenames')]
    public function test_terceira_camada_recusa_script_e_pagina(string $filename): void
    {
        $this->assertTrue($this->storageDenies($filename), "{$filename} precisa dar 403 pela terceira camada");
    }

    /** @return array<string, array{string}> */
    public static function photoFilenames(): array
    {
        return [
            'jpg' => ['01M8DK0VRR7KJCGFS7RWK551X3.jpg'], 'JPG' => ['01M8DK0VRR7KJCGFS7RWK551X3.JPG'],
            'jpeg' => ['01M8DK0VRR7KJCGFS7RWK551X3.jpeg'], 'jfif' => ['01M8DK0VRR7KJCGFS7RWK551X3.jfif'],
            'png' => ['01M8DK0VRR7KJCGFS7RWK551X3.png'], 'webp' => ['01M8DK0VRR7KJCGFS7RWK551X3.webp'],
        ];
    }

    #[DataProvider('photoFilenames')]
    public function test_terceira_camada_deixa_as_fotos(string $filename): void
    {
        $this->assertFalse($this->storageDenies($filename), "{$filename} não pode dar 403");
    }
}
