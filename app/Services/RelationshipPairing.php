<?php

namespace App\Services;

use App\Data\CreatedRelationshipInvitation;
use App\Models\Relationship;
use App\Models\RelationshipInvitation;
use App\Models\User;
use App\Notifications\PartnerJoinedNotification;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RelationshipPairing
{
    public function invite(User $inviter, string $email, string $timezone): CreatedRelationshipInvitation
    {
        $email = Str::lower(trim($email));

        if ($email === Str::lower($inviter->email)) {
            throw new DomainException('You cannot invite yourself.');
        }

        $token = Str::random(48);

        /** @var RelationshipInvitation $invitation */
        $invitation = DB::transaction(function () use ($inviter, $email, $timezone, $token): RelationshipInvitation {
            $inviter = User::query()->lockForUpdate()->findOrFail($inviter->id);
            $relationship = $inviter->relationships()->first();

            if ($relationship && $relationship->members()->count() >= 2) {
                throw new DomainException('You are already paired.');
            }

            if (! $relationship) {
                $relationship = Relationship::query()->create(['timezone' => $timezone]);
                $relationship->members()->attach($inviter->id, ['joined_at' => now()]);
            } else {
                $relationship->update(['timezone' => $timezone]);
            }

            $relationship->invitations()
                ->whereNull('accepted_at')
                ->whereNull('canceled_at')
                ->update(['canceled_at' => now()]);

            return $relationship->invitations()->create([
                'invited_by' => $inviter->id,
                'email' => $email,
                'token_hash' => hash('sha256', $token),
                'token' => $token,
                'expires_at' => now()->addDays(7),
            ]);
        });

        return new CreatedRelationshipInvitation($invitation, $token);
    }

    public function findInvitation(string $token): ?RelationshipInvitation
    {
        return RelationshipInvitation::query()
            ->with(['inviter', 'relationship'])
            ->where('token_hash', hash('sha256', $token))
            ->first();
    }

    public function accept(User $invitee, string $token): Relationship
    {
        /** @var array{relationship: Relationship, inviter: User} $result */
        $result = DB::transaction(function () use ($invitee, $token): array {
            $invitation = RelationshipInvitation::query()
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->firstOrFail();

            if (! $invitation->isPending()) {
                throw new DomainException('This invitation is no longer available.');
            }

            $invitee = User::query()->lockForUpdate()->findOrFail($invitee->id);

            if (Str::lower($invitee->email) !== Str::lower($invitation->email)) {
                throw new DomainException("This invitation was sent to {$invitation->email}.");
            }

            $relationship = Relationship::query()->lockForUpdate()->findOrFail($invitation->relationship_id);
            $otherRelationshipExists = $invitee->relationships()
                ->whereKeyNot($relationship->id)
                ->exists();

            if ($otherRelationshipExists) {
                throw new DomainException('You are already part of another relationship.');
            }

            if (! $relationship->hasMember($invitee) && $relationship->members()->count() >= 2) {
                throw new DomainException('This relationship is already full.');
            }

            $relationship->members()->syncWithoutDetaching([
                $invitee->id => ['joined_at' => now()],
            ]);
            $invitation->update(['accepted_at' => now()]);

            return ['relationship' => $relationship, 'inviter' => $invitation->inviter];
        });

        $result['inviter']->notify(new PartnerJoinedNotification($invitee->firstName()));

        return $result['relationship']->fresh(['members']);
    }

    public function cancel(User $user, RelationshipInvitation $invitation): void
    {
        if ($invitation->invited_by !== $user->id || ! $invitation->isPending()) {
            throw new DomainException('This invitation cannot be canceled.');
        }

        $invitation->update(['canceled_at' => now()]);
    }

    public function updateTimezone(User $user, Relationship $relationship, string $timezone): void
    {
        if (! $relationship->hasMember($user)) {
            throw new DomainException('You cannot update this relationship.');
        }

        $relationship->update(['timezone' => $timezone]);
    }
}
