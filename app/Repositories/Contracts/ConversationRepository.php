<?php

namespace App\Repositories\Contracts;

use App\Models\Business;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface ConversationRepository
{
    /** @return Collection<int, Conversation> */
    public function listForParticipant(Business $business, User $user): Collection;

    /** @param array{subject?: string|null} $attributes */
    public function createPrivate(Business $business, User $user, User $participant, array $attributes): Conversation;

    public function findPrivateBetween(Business $business, User $user, User $participant): ?Conversation;

    public function findForParticipant(Business $business, User $user, int $conversationId): Conversation;

    /** @return LengthAwarePaginator<int, Message> */
    public function messagesForParticipant(Conversation $conversation, User $user): LengthAwarePaginator;

    /** @param list<int> $documentIds */
    public function send(Conversation $conversation, User $user, string $body, array $documentIds): Message;
}
