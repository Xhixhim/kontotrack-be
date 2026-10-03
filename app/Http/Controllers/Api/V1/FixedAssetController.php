<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\DisposeFixedAssetRequest;
use App\Http\Requests\StoreFixedAssetRequest;
use App\Http\Requests\TransferFixedAssetRequest;
use App\Http\Resources\Api\V1\FixedAssetResource;
use App\Models\Business;
use App\Models\User;
use App\Services\Assets\FixedAssetService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FixedAssetController extends Controller
{
    public function __construct(private FixedAssetService $assetService) {}

    public function index(Business $business): AnonymousResourceCollection
    {
        return FixedAssetResource::collection($this->assetService->listAssets($business));
    }

    public function show(Business $business, int $fixedAsset): FixedAssetResource
    {
        return new FixedAssetResource($this->assetService->findAsset($business, $fixedAsset));
    }

    public function store(StoreFixedAssetRequest $request, Business $business): FixedAssetResource
    {
        /** @var User $user */
        $user = $request->user();

        return new FixedAssetResource($this->assetService->createAsset($business, $user, $request->validated()));
    }

    public function transfer(TransferFixedAssetRequest $request, Business $business, int $fixedAsset): FixedAssetResource
    {
        /** @var User $user */
        $user = $request->user();

        return new FixedAssetResource($this->assetService->transferAsset($business, $user, $fixedAsset, $request->validated()));
    }

    public function dispose(DisposeFixedAssetRequest $request, Business $business, int $fixedAsset): FixedAssetResource
    {
        /** @var User $user */
        $user = $request->user();

        return new FixedAssetResource($this->assetService->disposeAsset($business, $user, $fixedAsset, $request->validated()));
    }
}
