<?php

namespace App\Modules\Transformations\Http\Controllers;

use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Exceptions\StockNotInitializedException;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Transformations\Enums\TransformationType;
use App\Modules\Transformations\Exceptions\InvalidTransformationException;
use App\Modules\Transformations\Exceptions\TransformationAlreadyCancelledException;
use App\Modules\Transformations\Exceptions\UnknownCostException;
use App\Modules\Transformations\Http\Requests\CreateTransformationRequest;
use App\Modules\Transformations\Http\Resources\TransformationResource;
use App\Modules\Transformations\Models\Transformation;
use App\Modules\Transformations\Services\TransformationService;
use App\Shared\Http\Controllers\ApiController;
use Closure;
use Illuminate\Http\Request;

/**
 * Découpes (`/decoupes`) et préparations (`/preparations`) : même contrôleur,
 * le type vient de la route (chacune derrière sa feature). Une opération
 * d'un autre type que celui de la route donne 404.
 */
class TransformationController extends ApiController
{
    private const RELATIONS = ['items', 'createdBy', 'preparationSheet'];

    public function __construct(private readonly TransformationService $transformations) {}

    public function index(Store $store, Request $request)
    {
        $this->authorize('viewAny', [Transformation::class, $store]);

        $transformations = Transformation::query()
            ->with(self::RELATIONS)
            ->where('type', $this->type($request))
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->string('statut')))
            ->when($request->filled('produit_id'), fn ($q) => $q->whereHas('items', fn ($q) => $q->where('produit_id', $request->integer('produit_id'))))
            ->when($request->filled('du'), fn ($q) => $q->whereDate('effectuee_le', '>=', $request->date('du')))
            ->when($request->filled('au'), fn ($q) => $q->whereDate('effectuee_le', '<=', $request->date('au')))
            ->latest('effectuee_le')
            ->latest('id')
            ->paginate(min((int) $request->integer('par_page', 20), 100));

        return $this->success(TransformationResource::collection($transformations));
    }

    public function store(Store $store, CreateTransformationRequest $request)
    {
        $this->authorize('create', [Transformation::class, $store]);

        return $this->guard(function () use ($request) {
            $transformation = $this->transformations->record($this->type($request), $request->validated(), $request->user()?->id);

            return $this->success(new TransformationResource($transformation->load(self::RELATIONS)), 'Opération enregistrée.', [], $transformation->wasRecentlyCreated ? 201 : 200);
        });
    }

    public function show(Store $store, Transformation $transformation, Request $request)
    {
        $this->assertType($transformation, $request);
        $this->authorize('view', [$transformation, $store]);

        return $this->success(new TransformationResource($transformation->load(self::RELATIONS)));
    }

    /** POST /{type}/{transformation}/annuler — remet le stock dans l'état d'avant l'opération. */
    public function cancel(Store $store, Transformation $transformation, Request $request)
    {
        $this->assertType($transformation, $request);
        $this->authorize('cancel', [$transformation, $store]);
        $data = $request->validate(['motif' => ['required', 'string', 'max:500']]);

        return $this->guard(fn () => $this->success(
            new TransformationResource($this->transformations->cancel($transformation, $data['motif'], $request->user()?->id)->load(self::RELATIONS)),
            'Opération annulée.',
        ));
    }

    private function type(Request $request): TransformationType
    {
        return TransformationType::from($request->route()->defaults['type']);
    }

    private function assertType(Transformation $transformation, Request $request): void
    {
        abort_if($transformation->type !== $this->type($request), 404);
    }

    /** Erreurs métier → 422 avec un code exploitable (mêmes codes de stock que Sales). */
    private function guard(Closure $operation)
    {
        try {
            return $operation();
        } catch (InvalidTransformationException $e) {
            return $this->error($e->getMessage(), [], 422, 'TRANSFORMATION_INVALIDE');
        } catch (UnknownCostException $e) {
            return $this->error($e->getMessage(), [], 422, 'COUT_INCONNU');
        } catch (TransformationAlreadyCancelledException $e) {
            return $this->error($e->getMessage(), [], 422, 'TRANSFORMATION_DEJA_ANNULEE');
        } catch (InsufficientStockException $e) {
            return $this->error($e->getMessage(), [], 422, 'STOCK_INSUFFISANT');
        } catch (StockNotInitializedException $e) {
            return $this->error($e->getMessage().' Enregistrez d’abord son arrivée (achat ou stock initial).', [], 422, 'STOCK_NON_INITIALISE');
        }
    }
}
