<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\Size;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\GenerateSignedUploadUrl;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Characterization\Support\CharacterizationTestCase;

/**
 * M-80: o upload temporário do painel (Livewire) grava fora da pasta pública e só aceita foto de verdade
 * (conteúdo JPEG, PNG ou WebP e nome terminando em jpg, jpeg, jfif, png ou webp), em qualquer tela do painel.
 * Antes ia para storage/app/public/livewire-tmp com a extensão que o navegador mandasse (shell.php incluído).
 *
 * Os envios vão ao endereço de upload de verdade (`/livewire/upload-file`, com a assinatura que o painel entrega).
 * Em teste o Livewire troca o disco pelo `tmp-for-tests`, então a pasta real é conferida pela configuração.
 */
class PanelPhotoUploadTest extends CharacterizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        FileUploadConfiguration::storage();
        Storage::fake('public');
    }

    public function test_temporarios_ficam_num_disco_fora_da_pasta_publica(): void
    {
        $disk = config('livewire.temporary_file_upload.disk');
        $tmpDir = rtrim((string) config("filesystems.disks.{$disk}.root"), '/').'/'.config('livewire.temporary_file_upload.directory');

        $this->assertSame('local', $disk);
        $this->assertSame('local', config("filesystems.disks.{$disk}.driver"));
        $this->assertSame(storage_path('app/livewire-tmp'), $tmpDir);
        $this->assertStringStartsNotWith(storage_path('app/public').'/', $tmpDir.'/');
        $this->assertStringStartsNotWith(public_path().'/', $tmpDir.'/');
        $this->assertNotTrue(config("filesystems.disks.{$disk}.serve", false), 'o disco dos temporários não pode ter rota que publique os arquivos');
    }

    /** @return array<string, array{string, string}> */
    public static function notPhotos(): array
    {
        return [
            'php' => ['shell.php', "<?php echo 'M80';"],
            'pht' => ['shell.pht', "<?php echo 'M80';"],
            'phtml' => ['shell.phtml', "<?php echo 'M80';"],
            'phar' => ['shell.phar', "<?php echo 'M80';"],
            'html' => ['pagina.html', '<script>alert(1)</script>'],
            'svg' => ['desenho.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'js' => ['script.js', 'alert(1)'],
            'texto' => ['notas.txt', 'texto'],
        ];
    }

    #[DataProvider('notPhotos')]
    public function test_arquivo_que_nao_e_foto_e_recusado_e_nada_e_gravado(string $name, string $content): void
    {
        $this->sendToUploadEndpoint(UploadedFile::fake()->createWithContent($name, $content))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['files.0']);

        $this->assertNothingStored();
    }

    public function test_gif_e_recusado_como_o_campo_de_foto_ja_recusa(): void
    {
        $this->sendToUploadEndpoint(UploadedFile::fake()->image('foto.gif'))->assertStatus(422);

        $this->assertNothingStored();
    }

    /** @return array<string, array{string}> */
    public static function scriptNames(): array
    {
        return [
            'php' => ['foto.php'],
            'pht' => ['foto.pht'],
            'phtml' => ['foto.phtml'],
            'html' => ['foto.html'],
            'svg' => ['foto.svg'],
            'js' => ['foto.js'],
            'sem extensão' => ['foto'],
        ];
    }

    #[DataProvider('scriptNames')]
    public function test_jpeg_de_verdade_com_nome_de_script_e_recusado(string $name): void
    {
        $this->sendToUploadEndpoint($this->jpegWithPayload($name))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['files.0']);

        $this->assertNothingStored();
    }

    /** @return array<string, array{string}> */
    public static function photos(): array
    {
        return [
            'jpg' => ['foto.jpg'],
            'JPG maiúsculo' => ['FOTO.JPG'],
            'jpeg' => ['foto.jpeg'],
            'jfif (JPEG do Windows)' => ['foto.jfif'],
            'png' => ['foto.png'],
            'webp' => ['foto.webp'],
            // Nomes que o editor de imagem do Filament dá ao recorte (file-upload.js, saveEditor): o nome sem a
            // última extensão + "-v1" (ou -vN+1) + a mesma extensão. Foto recém-escolhida: o nome do arquivo do
            // computador; foto já salva: o FilePond usa o fim do endereço dela (/storage/products/<ULID>.jpg).
            'editor: foto nova recortada' => ['foto-v1.jpg'],
            'editor: foto salva recortada' => ['01M8DK0VRR7KJCGFS7RWK551X3-v1.jpg'],
            'editor: foto salva recortada de novo' => ['01M8DK0VRR7KJCGFS7RWK551X3-v2.png'],
        ];
    }

    #[DataProvider('photos')]
    public function test_foto_e_aceita_e_vai_para_a_pasta_temporaria(string $name): void
    {
        $file = str_ends_with($name, '.jfif') ? $this->jpeg($name) : UploadedFile::fake()->image($name, 40, 40);

        $response = $this->sendToUploadEndpoint($file)->assertOk();

        $path = TemporaryUploadedFile::extractPathFromSignedPath((string) $response->json('paths.0'));
        $this->assertIsString($path);
        $this->assertStringEndsWith('.'.pathinfo($name, PATHINFO_EXTENSION), $path);
        $this->assertTrue(FileUploadConfiguration::storage()->exists(FileUploadConfiguration::path($path)));
    }

    public function test_recorte_do_editor_com_conteudo_png_e_nome_jpg_e_aceito(): void
    {
        // O editor gera o recorte pelo canvas; se o navegador não souber o tipo da foto, o canvas sai em PNG.
        $png = UploadedFile::fake()->image('recorte.png', 40, 40);
        $file = UploadedFile::fake()->createWithContent('01M8DK0VRR7KJCGFS7RWK551X3-v1.jpg', (string) file_get_contents($png->getRealPath()));

        $this->sendToUploadEndpoint($file)->assertOk();
    }

    public function test_tela_sem_campo_de_foto_tambem_recusa_script(): void
    {
        Livewire::test(ListOrders::class)
            ->upload('campo_que_nao_existe', [UploadedFile::fake()->createWithContent('shell.php', "<?php echo 'M80';")])
            ->assertHasErrors(['campo_que_nao_existe']);

        $this->assertNothingStored();
    }

    public function test_salvar_produto_com_foto_grava_em_products_no_disco_publico(): void
    {
        $product = $this->sellableProduct();

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->set('data.images', ['item-1' => ['image_path' => [], 'alt_text' => 'Vaso lilás']])
            ->set('data.images.item-1.image_path', UploadedFile::fake()->image('FOTO.JPG', 400, 400))
            ->call('save')
            ->assertHasNoFormErrors();

        $paths = $product->fresh()->images()->pluck('image_path')->all();
        $this->assertCount(1, $paths);
        $this->assertMatchesRegularExpression('#^products/[0-9A-Z]{26}\.JPG$#', $paths[0]);
        Storage::disk('public')->assertExists($paths[0]);
    }

    public function test_salvar_produto_com_foto_recortada_no_editor_grava_em_products(): void
    {
        $product = $this->sellableProduct();
        $recorte = UploadedFile::fake()->image('01M8DK0VRR7KJCGFS7RWK551X3-v1.jpg', 300, 300);

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->set('data.images', ['item-1' => ['image_path' => [], 'alt_text' => 'Vaso lilás']])
            ->set('data.images.item-1.image_path', $recorte)
            ->call('save')
            ->assertHasNoFormErrors();

        $paths = $product->fresh()->images()->pluck('image_path')->all();
        $this->assertCount(1, $paths);
        $this->assertMatchesRegularExpression('#^products/[0-9A-Z]{26}\.jpg$#', $paths[0]);
        Storage::disk('public')->assertExists($paths[0]);
    }

    private function sendToUploadEndpoint(UploadedFile $file): TestResponse
    {
        return $this->withHeaders(['Accept' => 'application/json'])
            ->post(app(GenerateSignedUploadUrl::class)->forLocal(), ['files' => [$file]]);
    }

    private function assertNothingStored(): void
    {
        $this->assertSame([], FileUploadConfiguration::storage()->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    /** JPEG de verdade (o PHP detecta image/jpeg pelo conteúdo). */
    private function jpeg(string $name, string $suffix = ''): File
    {
        $image = UploadedFile::fake()->image('base.jpg', 20, 20);

        return UploadedFile::fake()->createWithContent($name, (string) file_get_contents($image->getRealPath()).$suffix);
    }

    /** JPEG de verdade com PHP e script colados no fim: passa por foto se só o conteúdo for conferido. */
    private function jpegWithPayload(string $name): File
    {
        return $this->jpeg($name, "\n<?php echo 'M80'; ?>\n<script>alert(1)</script>");
    }

    private function sellableProduct(): Product
    {
        $product = Product::factory()->materials(['pla'])->create(['title' => 'Vaso', 'base_price_cents' => 5000]);
        $product->categories()->attach(Category::factory()->create());
        $product->sizes()->attach(Size::factory()->create(['price_modifier_in_cents' => 0]));
        $product->colors()->attach(Color::factory()->create());

        return $product;
    }
}
