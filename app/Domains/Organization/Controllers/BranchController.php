<?php

namespace App\Domains\Organization\Controllers;

use App\Domains\Organization\Requests\StoreBranchRequest;
use App\Domains\Organization\Requests\UpdateBranchRequest;
use App\Domains\Organization\Resources\BranchResource;
use App\Domains\Organization\Services\BranchService;
use App\Domains\Organization\Services\BusinessContextService;
use App\Domains\Organization\Services\BranchContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BranchController
{
    public function __construct(
        private readonly BranchService $branchService,
        private readonly BusinessContextService $businessContextService,
        private readonly BranchContextService $branchContextService
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $business = $this->businessContextService->current($request->user());

        return BranchResource::collection(
            $this->branchService->listForBusiness($business)
        );
    }

    public function switch(
        Request $request,
        string $branch
    ): JsonResponse {
        $business = $this->businessContextService->current(
            $request->user()
        );

        $branchModel = $business
            ->branches()
            ->whereKey($branch)
            ->firstOrFail();

        $this->branchContextService->set(
            $request->user(),
            $business,
            $branchModel
        );

        return response()->json([
            'success' => true,
            'message' => 'Location switched successfully.',
            'data' => new BranchResource($branchModel),
        ]);
    }

    public function current(Request $request): JsonResponse
    {
        $business = $this->businessContextService->current(
            $request->user()
        );

        $branch = $this->branchContextService->current(
            $request->user(),
            $business
        );

        return response()->json([
            'success' => true,
            'data' => $branch
                ? new BranchResource($branch)
                : null,
        ]);
    }

    public function store(StoreBranchRequest $request): BranchResource
    {
        $business = $this->businessContextService->current($request->user());

        $branch = $this->branchService->create(
            $business,
            $request->validated()
        );

        return new BranchResource($branch);
    }

    public function update(
        UpdateBranchRequest $request,
        string $branch
    ): BranchResource {
        $business = $this->businessContextService->current($request->user());

        $branchModel = $business
            ->branches()
            ->whereKey($branch)
            ->firstOrFail();

        $updatedBranch = $this->branchService->update(
            $business,
            $branchModel,
            $request->validated()
        );

        return new BranchResource($updatedBranch);
    }

    public function destroy(
        Request $request,
        string $branch
    ): JsonResponse {
        $business = $this->businessContextService->current($request->user());

        $branchModel = $business
            ->branches()
            ->whereKey($branch)
            ->firstOrFail();

        $this->branchService->delete(
            $business,
            $branchModel
        );

        return response()->json([
            'success' => true,
            'message' => 'Branch deleted successfully.',
        ]);
    }
}
