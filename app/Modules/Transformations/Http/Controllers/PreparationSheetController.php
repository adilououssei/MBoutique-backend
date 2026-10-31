<?php

namespace App\Modules\Transformations\Http\Controllers;

use App\Modules\Tenancy\Models\Store;
use App\Modules\Transformations\Http\Requests\PreparationSheetRequest;
use App\Modules\Transformations\Http\Resources\PreparationSheetResource;
use App\Modules\Transformations\Models\PreparationSheet;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PreparationSheetController extends ApiController
{
    private const RELATIONS = ['product', 'ingredients.product'];

    public function index(Store $store, Request $request)
    {
        $this->authorize('viewAny', [PreparationSheet::class, $store]);

        $sheets = PreparationSheet::query()
            ->with(self::RELATIONS)
            ->when($request->has('actif'), fn ($q) => $q->where('actif', $request->boolean('actif')))
            ->when($request->filled('recherche'), fn ($q) => $q->where('nom', 'like', '%'.$request->string('recherche').'%'))
            ->orderBy('nom')
            ->paginate(min((int) $request->integer('par_page', 50), 100));

        return $this->success(PreparationSheetResource::collection($sheets));
    }

    public function store(Store $store, PreparationSheetRequest $request)
    {
        $this->authorize('create', [PreparationSheet::class, $store]);

        $sheet = DB::transaction(function () use ($request) {
            $sheet = PreparationSheet::create($request->safe()->except('ingredients'));
            $sheet->ingredients()->createMany($request->validated('ingredients'));

            return $sheet;
        });

        return $this->success(new PreparationSheetResource($sheet->load(self::RELATIONS)), 'Fiche enregistrée.', [], 201);
    }

    public function show(Store $store, PreparationSheet $preparationSheet)
    {
        $this->authorize('view', [$preparationSheet, $store]);

        return $this->success(new PreparationSheetResource($preparationSheet->load(self::RELATIONS)));
    }

    /** Les ingrédients envoyés remplacent la liste : les opérations passées gardent leurs propres lignes. */
    public function update(Store $store, PreparationSheet $preparationSheet, PreparationSheetRequest $request)
    {
        $this->authorize('update', [$preparationSheet, $store]);

        DB::transaction(function () use ($preparationSheet, $request) {
            $preparationSheet->update($request->safe()->except('ingredients'));
            if ($request->has('ingredients')) {
                $preparationSheet->ingredients()->delete();
                $preparationSheet->ingredients()->createMany($request->validated('ingredients'));
            }
        });

        return $this->success(new PreparationSheetResource($preparationSheet->refresh()->load(self::RELATIONS)), 'Fiche mise à jour.');
    }

    public function destroy(Store $store, PreparationSheet $preparationSheet)
    {
        $this->authorize('delete', [$preparationSheet, $store]);
        $preparationSheet->delete();

        return $this->success(null, 'Fiche supprimée.');
    }
}
