<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveComplianceRuleSetRequest;
use App\Http\Requests\StoreComplianceRuleSetRequest;
use App\Http\Requests\UpdateComplianceRuleSetRequest;
use App\Http\Resources\Api\V1\ComplianceRuleSetResource;
use App\Models\ComplianceRuleSet;
use App\Models\User;
use App\Services\Compliance\ComplianceRuleSetService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ComplianceRuleSetController extends Controller
{
    public function __construct(private ComplianceRuleSetService $complianceRuleSetService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'jurisdiction' => ['nullable', 'string', 'min:2', 'max:8'],
            'domain' => ['nullable', 'string', 'max:40'],
        ]);

        return ComplianceRuleSetResource::collection($this->complianceRuleSetService->listForUser(
            (bool) $request->user()?->is_system_admin,
            $filters['jurisdiction'] ?? null,
            $filters['domain'] ?? null,
        ));
    }

    public function effective(Request $request): ComplianceRuleSetResource
    {
        $filters = $request->validate([
            'jurisdiction' => ['sometimes', 'string', 'min:2', 'max:8'],
            'domain' => ['required', 'string', 'max:40'],
            'date' => ['required', 'date'],
        ]);
        $ruleSet = $this->complianceRuleSetService->findEffective(
            $filters['jurisdiction'] ?? 'XK',
            $filters['domain'],
            $filters['date'],
        );

        abort_if($ruleSet === null, 404, 'No approved compliance rules are effective for this date.');

        return new ComplianceRuleSetResource($ruleSet);
    }

    public function store(StoreComplianceRuleSetRequest $request): ComplianceRuleSetResource
    {
        /** @var User $user */
        $user = $request->user();

        return new ComplianceRuleSetResource($this->complianceRuleSetService->createDraft($user, $request->validated()));
    }

    public function update(UpdateComplianceRuleSetRequest $request, ComplianceRuleSet $ruleSet): ComplianceRuleSetResource
    {
        /** @var User $user */
        $user = $request->user();

        return new ComplianceRuleSetResource($this->complianceRuleSetService->updateDraft($user, (int) $ruleSet->getKey(), $request->validated()));
    }

    public function approve(ApproveComplianceRuleSetRequest $request, ComplianceRuleSet $ruleSet): ComplianceRuleSetResource
    {
        /** @var User $user */
        $user = $request->user();

        return new ComplianceRuleSetResource($this->complianceRuleSetService->approve($user, (int) $ruleSet->getKey()));
    }
}
