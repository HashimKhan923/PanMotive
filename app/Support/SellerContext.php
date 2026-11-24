<?php

namespace App\Support;

use App\Models\SellerTeamMember;
use App\Models\User;

/**
 * Resolves who the seller portal is acting *as* (the store owner) versus who is
 * actually clicking (the owner themselves or one of their team members).
 *
 * ResolveSellerActor middleware rebinds Auth to the owner for seller-portal
 * requests, then records the real human here so audit trails stay honest.
 */
class SellerContext
{
    private static ?User $owner = null;
    private static ?User $actor = null;
    private static ?SellerTeamMember $membership = null;

    /** @var array<int,bool> user id => owns a store (only used before the team-member step has run) */
    private static array $ownsStore = [];

    public static function set(User $owner, User $actor, ?SellerTeamMember $membership = null): void
    {
        self::$owner = $owner;
        self::$actor = $actor;
        self::$membership = $membership;
    }

    public static function clear(): void
    {
        self::$owner = self::$actor = null;
        self::$membership = null;
        self::$ownsStore = [];
    }

    /** The store owner — the user every seller-portal query should scope to. */
    public static function owner(): ?User
    {
        return self::$owner ?? auth()->user();
    }

    public static function ownerId(): ?int
    {
        return self::owner()?->id;
    }

    /** The real human performing the action (owner or team member). */
    public static function actor(): ?User
    {
        // Guard against stale static state (e.g. between test cases): if the
        // recorded actor no longer matches the authenticated user, trust auth.
        if (self::$actor && auth()->check() && self::$actor->id !== auth()->id() && ! self::$membership) {
            return auth()->user();
        }

        return self::$actor ?? auth()->user();
    }

    public static function membership(): ?SellerTeamMember
    {
        return self::$membership;
    }

    public static function isTeamMember(): bool
    {
        return self::$membership !== null;
    }

    /** True when the current actor may perform $ability for this store. */
    public static function can(string $ability): bool
    {
        // The owner (no membership context) can do everything. On the few seller pages that run before the
        // team-member step (onboarding, "application pending", team invites) that must really be a store
        // owner, or a team member there would be shown the owner's whole menu.
        if (! self::$membership) {
            if (self::$owner) {
                return true;
            }
            $user = auth()->user();

            return ! $user || (self::$ownsStore[$user->id] ??= $user->sellerProfile()->exists());
        }

        return self::$membership->can($ability);
    }

    /** @return array<int,string> */
    public static function abilities(): array
    {
        return self::$membership
            ? (self::$membership->abilities ?? [])
            : array_keys(SellerTeamMember::ABILITIES);
    }
}
