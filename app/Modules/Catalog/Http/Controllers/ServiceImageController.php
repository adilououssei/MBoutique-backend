<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Http\Requests\UploadProductImageRequest;
use App\Modules\Catalog\Http\Resources\ServiceResource;
use App\Modules\Catalog\Models\Service;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Photo optionnelle d'une prestation (salon), même principe que ProductImageController. */
class ServiceImageController extends ApiController
{
    public function store(Store $store, Service $service, UploadProductImageRequest $request)
    {
        $this->authorize('update', [$service, $store]);

        $file = $request->file('image');
        $path = $file->storeAs("services/{$store->id}", Str::uuid().'.'.$file->extension(), 'public');

        $previous = $service->image;
        $service->forceFill(['image' => $path])->save();
        if ($previous) {
            Storage::disk('public')->delete($previous);
        }

        return $this->success(new ServiceResource($service->load('category')), 'Photo de la prestation enregistrée.');
    }

    public function destroy(Store $store, Service $service)
    {
        $this->authorize('update', [$service, $store]);

        if ($service->image) {
            Storage::disk('public')->delete($service->image);
            $service->forceFill(['image' => null])->save();
        }

        return $this->success(new ServiceResource($service->load('category')), 'Photo de la prestation supprimée.');
    }
}
