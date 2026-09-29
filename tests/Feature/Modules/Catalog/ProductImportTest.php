<?php

namespace Tests\Feature\Modules\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

class ProductImportTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    private const HEADINGS = [
        'nom', 'categorie', 'description', 'sku', 'code_barres', 'unite',
        'prix_achat', 'vente_detail_active', 'prix_detail',
        'vente_gros_active', 'prix_gros', 'actif',
    ];

    public function test_owner_can_import_valid_products(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'categories']);
        Category::factory()->for($store)->create(['nom' => 'Boissons']);
        Sanctum::actingAs($owner);

        $file = $this->xlsx([
            ['Coca-Cola 50cl', 'Boissons', 'Bouteille', 'CC001', '123456789', 'piece', 300, 'true', 500, 'true', 450, 'true'],
            ['Fanta 50cl', 'Boissons', '', 'CC002', '987654321', 'piece', 280, 'true', 480, 'false', '', 'true'],
        ]);

        $response = $this->postJson("/api/boutiques/{$store->id}/produits/importer", ['fichier' => $file]);

        $response->assertStatus(200)
            ->assertJsonPath('donnees.total_lignes', 2)
            ->assertJsonPath('donnees.importes', 2)
            ->assertJsonPath('donnees.rejetes', 0);

        $this->assertDatabaseHas('produits', ['boutique_id' => $store->id, 'sku' => 'CC001', 'prix_detail' => 500]);
        $this->assertDatabaseHas('produits', ['boutique_id' => $store->id, 'sku' => 'CC002', 'prix_gros' => null]);
    }

    public function test_import_partially_succeeds_and_reports_rejected_lines(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'categories']);
        Category::factory()->for($store)->create(['nom' => 'Boissons']);
        Sanctum::actingAs($owner);

        $file = $this->xlsx([
            // valid
            ['Coca-Cola 50cl', 'Boissons', '', 'CC001', '111', 'piece', 300, 'true', 500, '', '', 'true'],
            // invalid: unknown category
            ['Sprite 50cl', 'Boisson', '', 'CC003', '333', 'piece', 300, 'true', 500, '', '', 'true'],
            // invalid: retail_price required when retail_enabled=true
            ['Ice Tea 50cl', 'Boissons', '', 'CC004', '444', 'piece', 300, 'true', '', '', '', 'true'],
        ]);

        $response = $this->postJson("/api/boutiques/{$store->id}/produits/importer", ['fichier' => $file]);

        $response->assertStatus(200)
            ->assertJsonPath('donnees.total_lignes', 3)
            ->assertJsonPath('donnees.importes', 1)
            ->assertJsonPath('donnees.rejetes', 2);

        $errors = $response->json('donnees.erreurs');
        $this->assertCount(2, $errors);
        $this->assertSame(3, $errors[0]['ligne']); // header = line 1, first data row = line 2
        $this->assertArrayHasKey('categorie', $errors[0]['erreurs']);
        $this->assertSame(4, $errors[1]['ligne']);
        $this->assertArrayHasKey('prix_detail', $errors[1]['erreurs']);

        $this->assertDatabaseHas('produits', ['boutique_id' => $store->id, 'sku' => 'CC001']);
        $this->assertDatabaseMissing('produits', ['sku' => 'CC003']);
        $this->assertDatabaseMissing('produits', ['sku' => 'CC004']);
    }

    public function test_import_rejects_a_duplicate_sku_within_the_same_file(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits']);
        Sanctum::actingAs($owner);

        $file = $this->xlsx([
            ['Article A', '', '', 'SKU-1', '', 'piece', '', 'true', 100, '', '', 'true'],
            ['Article B', '', '', 'SKU-1', '', 'piece', '', 'true', 100, '', '', 'true'],
        ]);

        $response = $this->postJson("/api/boutiques/{$store->id}/produits/importer", ['fichier' => $file]);

        $response->assertStatus(200)
            ->assertJsonPath('donnees.importes', 1)
            ->assertJsonPath('donnees.rejetes', 1);

        $this->assertSame(1, Product::where('boutique_id', $store->id)->where('sku', 'SKU-1')->count());
    }

    public function test_import_is_scoped_to_the_current_store(): void
    {
        ['proprietaire' => $ownerA, 'store' => $storeA] = $this->createStoreWithFeatures(['produits']);
        ['store' => $storeB] = $this->createStoreWithFeatures(['produits']);
        Sanctum::actingAs($ownerA);

        $file = $this->xlsx([
            ['Article A', '', '', '', '', 'piece', '', 'true', 100, '', '', 'true'],
        ]);

        $this->postJson("/api/boutiques/{$storeA->id}/produits/importer", ['fichier' => $file])->assertStatus(200);

        $this->assertSame(1, Product::where('boutique_id', $storeA->id)->count());
        $this->assertSame(0, Product::where('boutique_id', $storeB->id)->count());
    }

    public function test_import_requires_the_products_import_permission(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits']);
        $employee = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$store->id}/membres", ['email' => $employee->email, 'role' => 'employe'])
            ->assertStatus(201);

        Sanctum::actingAs($employee);

        $file = $this->xlsx([['Article A', '', '', '', '', 'piece', '', 'true', 100, '', '', 'true']]);

        $this->postJson("/api/boutiques/{$store->id}/produits/importer", ['fichier' => $file])->assertStatus(403);
    }

    public function test_import_rejects_a_non_excel_file(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits']);
        Sanctum::actingAs($owner);

        $file = UploadedFile::fake()->create('produits.pdf', 10, 'application/pdf');

        $this->postJson("/api/boutiques/{$store->id}/produits/importer", ['fichier' => $file])
            ->assertStatus(422)->assertJsonValidationErrors('fichier', 'erreurs');
    }

    public function test_import_rejects_a_file_that_is_too_large(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits']);
        Sanctum::actingAs($owner);

        $file = UploadedFile::fake()->create('produits.xlsx', 6000);

        $this->postJson("/api/boutiques/{$store->id}/produits/importer", ['fichier' => $file])
            ->assertStatus(422)->assertJsonValidationErrors('fichier', 'erreurs');
    }

    public function test_the_import_template_can_be_downloaded(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits']);
        Sanctum::actingAs($owner);

        $response = $this->get("/api/boutiques/{$store->id}/produits/importer/modele");

        $response->assertStatus(200);
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type')
        );
    }

    private function xlsx(array $rows): UploadedFile
    {
        $headings = self::HEADINGS;

        $export = new class($rows, $headings) implements Export, FromArray, WithHeadings
        {
            public function __construct(private readonly array $rows, private readonly array $headings) {}

            public function array(): array
            {
                return $this->rows;
            }

            public function headings(): array
            {
                return $this->headings;
            }
        };

        $binary = Excel::raw($export, ExcelFormat::XLSX);

        return UploadedFile::fake()->createWithContent('produits.xlsx', $binary);
    }
}
