<?php

namespace App\Repositories\Eloquent;

use App\Models\Business;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Repositories\Contracts\ConversationRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class EloquentConversationRepository implements ConversationRepository
{
    public function listForParticipant(Business $business, User $user): Collection
    {
        return Conversation::query()
            ->where('business_id', $business->getKey())
            ->whereHas('participants', fn ($query) => $query->whereKey($user->getKey()))
            ->with(['participants:id,name'])
            ->latest('updated_at')
            ->get();
    }

    /** @param array{subject?: string|null} $attributes */
    public function createPrivate(Business $business, User $user, User $participant, array $attributes): Conversation
    {
        $conversation = $business->conversations()->create(['subject' => $attributes['subject'] ?? null]);
        $conversation->participants()->attach([$user->getKey(), $participant->getKey()]);

        return $conversation->load(['participants:id,name']);
    }

    public function findPrivateBetween(Business $business, User $user, User $participant): ?Conversation
    {
        return Conversation::query()
            ->where('business_id', $business->getKey())
            ->whereHas('participants', fn ($query) => $query->whereKey($user->getKey()))
            ->whereHas('participants', fn ($query) => $query->whereKey($participant->getKey()))
            ->withCount('participants')
            ->having('participants_count', 2)
            ->with(['participants:id,name'])
            ->first();
    }

    public function findForParticipant(Business $business, User $user, int $conversationId): Conversation
    {
        return Conversation::query()
            ->where('business_id', $business->getKey())
            ->whereKey($conversationId)
            ->whereHas('participants', fn ($query) => $query->whereKey($user->getKey()))
            ->firstOrFail();
    }

    /** @return LengthAwarePaginator<int, Message> */
    public function messagesForParticipant(Conversation $conversation, User $user): LengthAwarePaginator
    {
        $conversation->participants()->updateExistingPivot($user->getKey(), ['last_read_at' => now()]);

        return $conversation->messages()
            ->with(['sender:id,name', 'documents:id,original_name,mime_type,size_bytes'])
            ->latest('id')
            ->paginate(50);
    }

    /** @param list<int> $documentIds */
    public function send(Conversation $conversation, User $user, string $body, array $documentIds): Message
    {
        $message = $conversation->messages()->create(['sender_id' => $user->getKey(), 'body' => $body]);

        if ($documentIds !== []) {
            $message->documents()->attach($documentIds);
        }

        return $message->load(['sender:id,name', 'documents:id,original_name,mime_type,size_bytes']);
    }
}
