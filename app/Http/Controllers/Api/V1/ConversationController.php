<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreConversationRequest;
use App\Http\Requests\StoreMessageRequest;
use App\Http\Resources\Api\V1\ConversationResource;
use App\Http\Resources\Api\V1\MessageResource;
use App\Models\Business;
use App\Models\User;
use App\Services\Conversations\ConversationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ConversationController extends Controller
{
    public function __construct(private ConversationService $conversationService) {}

    public function index(Request $request, Business $business): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return ConversationResource::collection($this->conversationService->listForParticipant($business, $user));
    }

    public function store(StoreConversationRequest $request, Business $business): ConversationResource
    {
        /** @var User $user */
        $user = $request->user();

        return new ConversationResource($this->conversationService->createPrivate($business, $user, $request->validated()));
    }

    public function messages(Request $request, Business $business, int $conversation): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return MessageResource::collection($this->conversationService->messagesForParticipant($business, $user, $conversation));
    }

    public function send(StoreMessageRequest $request, Business $business, int $conversation): MessageResource
    {
        /** @var User $user */
        $user = $request->user();

        return new MessageResource($this->conversationService->send($business, $user, $conversation, $request->validated()));
    }
}
