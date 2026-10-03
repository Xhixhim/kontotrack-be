<?php

namespace App\Services\Conversations;

use App\Models\Business;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Repositories\Contracts\ConversationRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConversationService
{
    public function __construct(private ConversationRepository $conversationRepository) {}

    /** @return Collection<int, Conversation> */
    public function listForParticipant(Business $business, User $user): Collection
    {
        return $this->conversationRepository->listForParticipant($business, $user);
    }

    /** @param array{participant_id: int, subject?: string|null} $attributes */
    public function createPrivate(Business $business, User $user, array $attributes): Conversation
    {
        if ($user->getKey() === $attributes['participant_id']) {
            throw ValidationException::withMessages(['participant_id' => ['Choose another business member.']]);
        }

        $members = $business->users()
            ->whereIn('users.id', [$user->getKey(), $attributes['participant_id']])
            ->wherePivot('status', 'active')
            ->wherePivotIn('role', ['owner', 'accountant', 'assistant_accountant'])
            ->get();

        if ($members->count() !== 2) {
            throw ValidationException::withMessages(['participant_id' => ['The selected member is not available for a private business chat.']]);
        }

        $participant = $members->firstWhere('id', $attributes['participant_id']);

        if (! $participant instanceof User) {
            throw ValidationException::withMessages(['participant_id' => ['The selected member is not available for a private business chat.']]);
        }

        return DB::transaction(function () use ($business, $user, $participant, $attributes): Conversation {
            $conversation = $this->conversationRepository->findPrivateBetween($business, $user, $participant);

            if ($conversation !== null) {
                return $conversation;
            }

            $conversation = $this->conversationRepository->createPrivate($business, $user, $participant, $attributes);
            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'conversation.created',
                'subject_type' => Conversation::class,
                'subject_id' => $conversation->getKey(),
            ]);

            return $conversation;
        });
    }

    /** @return LengthAwarePaginator<int, Message> */
    public function messagesForParticipant(Business $business, User $user, int $conversationId): LengthAwarePaginator
    {
        $conversation = $this->conversationRepository->findForParticipant($business, $user, $conversationId);

        return $this->conversationRepository->messagesForParticipant($conversation, $user);
    }

    /** @param array{body: string, document_ids?: list<int>} $attributes */
    public function send(Business $business, User $user, int $conversationId, array $attributes): Message
    {
        $conversation = $this->conversationRepository->findForParticipant($business, $user, $conversationId);
        $documentIds = $attributes['document_ids'] ?? [];

        if ($documentIds !== [] && $business->documents()->whereIn('id', $documentIds)->count() !== count(array_unique($documentIds))) {
            throw ValidationException::withMessages(['document_ids' => ['Choose documents belonging to this business.']]);
        }

        return DB::transaction(function () use ($conversation, $business, $user, $attributes, $documentIds): Message {
            $message = $this->conversationRepository->send($conversation, $user, $attributes['body'], $documentIds);
            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'conversation.message_sent',
                'subject_type' => Conversation::class,
                'subject_id' => $conversation->getKey(),
            ]);

            return $message;
        });
    }
}
