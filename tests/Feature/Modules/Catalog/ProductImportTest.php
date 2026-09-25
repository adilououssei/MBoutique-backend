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
        'name', 'category', 'description', 'sku', 'barcode', 'unit',
        'purchase_price', 'retail_enabled', 'retail_price',
        'wholesale_enabled', 'wholesale_price', 'is_active',
    ];

    public function test_owner_can_import_valid_products(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products', 'categories']);
        Category::factory()->for($store)->create(['name' => 'Boissons']);
        Sanctum::actingAs($owner);

        $file = $this->xlsx([
            ['Coca-Cola 50cl', 'Boissons', 'Bouteille', 'CC001', '123456789', 'piece', 300, 'true', 500, 'true', 450, 'true'],
            ['Fanta 50cl', 'Boissons', '', 'CC002', '987654321', 'piece', 280, 'true', 480, 'false', '', 'true'],
        ]);

        $response = $this->postJson("/api/stores/{$store->id}/products/import", ['file' => $file]);

        $response->assertStatus(200)
            ->assertJsonPath('data.total_rows', 2)
            ->assertJsonPath('data.imported', 2)
            ->assertJsonPath('data.rejected', 0);

        $this->assertDatabaseHas('products', ['store_id' => $store->id, 'sku' => 'CC001', 'retail_price' => 500]);
        $this->assertDatabaseHas('products', ['store_id' => $store->id, 'sku' => 'CC002', 'wholesale_price' => null]);
    }

    public function test_import_partially_succeeds_and_reports_rejected_lines(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products', 'categories']);
        Category::factory()->for($store)->create(['name' => 'Boissons']);
        Sanctum::actingAs($owner);

        $file = $this->xlsx([
            // valid
            ['Coca-Cola 50cl', 'Boissons', '', 'CC001', '111', 'piece', 300, 'true', 500, '', '', 'true'],
            // invalid: unknown category
            ['Sprite 50cl', 'Boisson', '', 'CC003', '333', 'piece', 300, 'true', 500, '', '', 'true'],
            // invalid: retail_price required when retail_enabled=true
            ['Ice Tea 50cl', 'Boissons', '', 'CC004', '444', 'piece', 300, 'true', '', '', '', 'true'],
        ]);

        $response = $this->postJson("/api/stores/{$store->id}/products/import", ['file' => $file]);

        $response->assertStatus(200)
            ->assertJsonPath('data.total_rows', 3)
            ->assertJsonPath('data.imported', 1)
            ->assertJsonPath('data.rejected', 2);

        $errors = $response->json('data.errors');
        $this->assertCount(2, $errors);
        $this->assertSame(3, $errors[0]['line']); // header = line 1, first data row = line 2
        $this->assertArrayHasKey('category', $errors[0]['errors']);
        $this->assertSame(4, $errors[1]['line']);
        $this->assertArrayHasKey('retail_price', $errors[1]['errors']);

        $this->assertDatabaseHas('products', ['store_id' => $store->id, 'sku' => 'CC001']);
        $this->assertDatabaseMissing('products', ['sku' => 'CC003']);
        $this->assertDatabaseMissing('products', ['sku' => 'CC004']);
    }

    public function test_import_rejects_a_duplicate_sku_within_the_same_file(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products']);
        Sanctum::actingAs($owner);

        $file = $this->xlsx([
            ['Article A', '', '', 'SKU-1', '', 'piece', '', 'true', 100, '', '', 'true'],
            ['Article B', '', '', 'SKU-1', '', 'piece', '', 'true', 100, '', '', 'true'],
        ]);

        $response = $this->postJson("/api/stores/{$store->id}/products/import", ['file' => $file]);

        $response->assertStatus(200)
            ->assertJsonPath('data.imported', 1)
            ->assertJsonPath('data.rejected', 1);

        $this->assertSame(1, Product::where('store_id', $store->id)->where('sku', 'SKU-1')->count());
    }

    public function test_import_is_scoped_to_the_current_store(): void
    {
        ['owner' => $ownerA, 'store' => $storeA] = $this->createStoreWithFeatures(['products']);
        ['store' => $storeB] = $this->createStoreWithFeatures(['products']);
        Sanctum::actingAs($ownerA);

        $file = $this->xlsx([
            ['Article A', '', '', '', '', 'piece', '', 'true', 100, '', '', 'true'],
        ]);

        $this->postJson("/api/stores/{$storeA->id}/products/import", ['file' => $file])->assertStatus(200);

        $this->assertSame(1, Product::where('store_id', $storeA->id)->count());
        $this->assertSame(0, Product::where('store_id', $storeB->id)->count());
    }

    public function test_import_requires_the_products_import_permission(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products']);
        $employee = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/stores/{$store->id}/members", ['email' => $employee->email, 'role' => 'employee'])
            ->assertStatus(201);

        Sanctum::actingAs($employee);

        $file = $this->xlsx([['Article A', '', '', '', '', 'piece', '', 'true', 100, '', '', 'true']]);

        $this->postJson("/api/stores/{$store->id}/products/import", ['file' => $file])->assertStatus(403);
    }

    public function test_import_rejects_a_non_excel_file(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products']);
        Sanctum::actingAs($owner);

        $file = UploadedFile::fake()->create('products.pdf', 10, 'application/pdf');

        $this->postJson("/api/stores/{$store->id}/products/import", ['file' => $file])
            ->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_import_rejects_a_file_that_is_too_large(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products']);
        Sanctum::actingAs($owner);

        $file = UploadedFile::fake()->create('products.xlsx', 6000);

        $this->postJson("/api/stores/{$store->id}/products/import", ['file' => $file])
            ->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_the_import_template_can_be_downloaded(): void
    {
        ['owner' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['products']);
        Sanctum::actingAs($owner);

        $response = $this->get("/api/stores/{$store->id}/products/import/template");

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

        return UploadedFile::fake()->createWithContent('products.xlsx', $binary);
    }
}
