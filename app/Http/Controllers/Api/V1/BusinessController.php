<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBusinessMemberRequest;
use App\Http\Requests\StoreBusinessRequest;
use App\Http\Requests\UpdateBusinessMemberRequest;
use App\Http\Requests\UpdateBusinessRequest;
use App\Http\Resources\Api\V1\BusinessMemberResource;
use App\Http\Resources\Api\V1\BusinessResource;
use App\Http\Resources\BusinessInvitationResource;
use App\Http\Resources\BusinessTeamMemberResource;
use App\Models\Business;
use App\Models\User;
use App\Services\Businesses\BusinessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class BusinessController extends Controller
{
    public function __construct(private BusinessService $businessService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return BusinessResource::collection($this->businessService->listForUser($user));
    }

    public function store(StoreBusinessRequest $request): BusinessResource
    {
        /** @var User $user */
        $user = $request->user();

        return new BusinessResource($this->businessService->createForOwner($user, $request->validated()));
    }

    public function update(UpdateBusinessRequest $request, Business $business): BusinessResource
    {
        /** @var User $user */
        $user = $request->user();

        return new BusinessResource($this->businessService->update($business, $user, $request->validated()));
    }

    public function members(Business $business): AnonymousResourceCollection
    {
        return BusinessMemberResource::collection($this->businessService->chatMembers($business));
    }

    public function team(Business $business): AnonymousResourceCollection
    {
        return BusinessTeamMemberResource::collection($this->businessService->membersForManagement($business));
    }

    public function invite(StoreBusinessMemberRequest $request, Business $business): BusinessTeamMemberResource
    {
        /** @var User $user */
        $user = $request->user();

        return new BusinessTeamMemberResource($this->businessService->inviteMember($business, $user, $request->validated()));
    }

    public function updateMember(UpdateBusinessMemberRequest $request, Business $business, User $member): BusinessTeamMemberResource
    {
        /** @var User $user */
        $user = $request->user();

        return new BusinessTeamMemberResource($this->businessService->updateMember($business, $user, $member, $request->validated()));
    }

    public function removeMember(Request $request, Business $business, User $member): Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->businessService->removeMember($business, $user, $member);

        return response()->noContent();
    }

    public function invitations(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return BusinessInvitationResource::collection($this->businessService->invitationsForUser($user));
    }

    public function acceptInvitation(Request $request, Business $business): BusinessMemberResource
    {
        /** @var User $user */
        $user = $request->user();

        return new BusinessMemberResource($this->businessService->acceptInvitation($business, $user));
    }
}
