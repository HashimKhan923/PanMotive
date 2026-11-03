<?php

namespace App\Services\Seller;

use App\Models\SellerTeamActivity;
use App\Models\SellerTeamMember;
use App\Models\User;
use App\Notifications\SellerTeamInvite;
use App\Support\SellerContext;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class TeamService
{
    public const INVITE_TTL_DAYS = 14;

    /** @param array<int,string>|null $abilities */
    public function invite(User $owner, string $email, string $role, ?array $abilities = null, ?string $name = null): SellerTeamMember
    {
        $email = mb_strtolower(trim($email));

        if ($email === mb_strtolower($owner->email)) {
            throw ValidationException::withMessages(['email' => "That's the owner's address."]);
        }

        $member = SellerTeamMember::updateOrCreate(
            ['owner_id' => $owner->id, 'email' => $email],
            [
                'name' => $name,
                'role' => $role,
                'abilities' => $abilities ?: SellerTeamMember::presetAbilities($role),
                'status' => 'invited',
                'invite_token' => \Illuminate\Support\Str::random(40),
                'invited_at' => now(),
                'accepted_at' => null,
            ],
        );

        // Link straight away if that person already has an account.
        $existing = User::where('email', $email)->first();
        if ($existing && $member->user_id === null) {
            $member->user_id = $existing->id;
            $member->save();
        }

        Notification::route('mail', $email)->notify(new SellerTeamInvite($member));

        $this->log($owner, "invited {$email} as {$role}", $email);

        return $member;
    }

    public function accept(string $token, User $user): SellerTeamMember
    {
        $member = SellerTeamMember::where('invite_token', $token)->firstOrFail();

        if ($member->status === 'revoked') {
            throw ValidationException::withMessages(['token' => 'This invitation was withdrawn.']);
        }
        if ($member->invited_at && $member->invited_at->lt(now()->subDays(self::INVITE_TTL_DAYS))) {
            throw ValidationException::withMessages(['token' => 'This invitation has expired. Ask for a new one.']);
        }
        if ($member->email !== mb_strtolower($user->email)) {
            throw ValidationException::withMessages(['token' => 'This invitation is for a different email address.']);
        }

        $member->update([
            'user_id' => $user->id,
            'name' => $member->name ?: $user->name,
            'status' => 'active',
            'accepted_at' => now(),
            'invite_token' => null,
        ]);

        $this->log($member->owner, "{$user->name} joined the team", $user->email, actor: $user);

        return $member;
    }

    public function updateRole(SellerTeamMember $member, string $role, ?array $abilities = null): void
    {
        $member->update([
            'role' => $role,
            'abilities' => $abilities ?: SellerTeamMember::presetAbilities($role),
        ]);
        $this->log($member->owner, "changed {$member->email} to {$role}", $member->email);
    }

    public function setStatus(SellerTeamMember $member, string $status): void
    {
        abort_unless(in_array($status, ['active', 'suspended', 'revoked'], true), 422);
        // Someone who never accepted can't be switched "on" — they'd be active with no account.
        abort_if($status === 'active' && ! $member->user_id, 422, 'They haven\'t accepted the invitation yet — resend it instead.');
        $member->update(['status' => $status]);
        $this->log($member->owner, "{$status} {$member->email}", $member->email);
    }

    public function resend(SellerTeamMember $member): void
    {
        // Resending resets the member to "invited" — never do that to someone already on the team.
        abort_unless(in_array($member->status, ['invited', 'revoked'], true) && ! $member->accepted_at, 422, 'They\'re already on the team.');
        $member->update(['invite_token' => \Illuminate\Support\Str::random(40), 'invited_at' => now(), 'status' => 'invited']);
        Notification::route('mail', $member->email)->notify(new SellerTeamInvite($member));
        $this->log($member->owner, "resent invite to {$member->email}", $member->email);
    }

    public function expireStaleInvites(): int
    {
        return SellerTeamMember::where('status', 'invited')
            ->where('invited_at', '<', now()->subDays(self::INVITE_TTL_DAYS))
            ->update(['status' => 'revoked', 'invite_token' => null]);
    }

    /** Append a line to the store's activity log. */
    public function log(User $owner, string $action, ?string $subject = null, array $meta = [], ?User $actor = null): void
    {
        $actor ??= SellerContext::actor() ?? $owner;

        SellerTeamActivity::create([
            'owner_id' => $owner->id,
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'action' => $action,
            'subject' => $subject,
            'meta' => $meta ?: null,
        ]);
    }
}
