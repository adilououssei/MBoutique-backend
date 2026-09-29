<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Http\Requests\CreateCategoryRequest;
use App\Modules\Catalog\Http\Requests\UpdateCategoryRequest;
use App\Modules\Catalog\Http\Resources\CategoryResource;
use App\Modules\Catalog\Models\Category;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Http\Request;

class CategoryController extends ApiController
{
    public function index(Store $store, Request $request)
    {
        $this->authorize('viewAny', [Category::class, $store]);

        $categories = Category::query()
            ->when($request->filled('recherche'), fn ($q) => $q->where('nom', 'like', '%'.$request->string('recherche').'%'))
            ->when($request->has('actif'), fn ($q) => $q->where('actif', $request->boolean('actif')))
            ->orderBy('nom')
            ->paginate(min((int) $request->integer('par_page', 20), 100));

        return $this->success(CategoryResource::collection($categories));
    }

    public function store(Store $store, CreateCategoryRequest $request)
    {
        $this->authorize('create', [Category::class, $store]);

        $category = Category::create($request->validated());

        return $this->success(new CategoryResource($category), 'Catégorie créée avec succès.', [], 201);
    }

    public function show(Store $store, Category $category)
    {
        $this->authorize('view', [$category, $store]);

        return $this->success(new CategoryResource($category));
    }

    public function update(Store $store, Category $category, UpdateCategoryRequest $request)
    {
        $this->authorize('update', [$category, $store]);

        $category->update($request->validated());

        return $this->success(new CategoryResource($category), 'Catégorie mise à jour.');
    }

    public function destroy(Store $store, Category $category)
    {
        $this->authorize('delete', [$category, $store]);

        $category->delete();

        return $this->success(null, 'Catégorie supprimée.');
    }
}
