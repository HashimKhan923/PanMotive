<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use NotificationChannels\WebPush\HasPushSubscriptions;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPushSubscriptions, HasRoles, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'wallet_balance',
        'is_active',
        'loyalty_tier',
        'lifetime_spend',
        'referral_code',
        'active_vehicle',
        'plus_until',
        'plus_auto_renew',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'wallet_balance' => 'decimal:2',
            'lifetime_spend' => 'decimal:2',
            'is_active' => 'boolean',
            'plus_until' => 'datetime',
            'plus_auto_renew' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'sms_opt_in_at' => 'datetime',
            'active_vehicle' => 'array',
        ];
    }

    public function sellerProfile(): HasOne
    {
        return $this->hasOne(SellerProfile::class);
    }

    /** Products this user sells. */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'seller_id');
    }

    /** Orders this user has placed as a buyer. */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'buyer_id');
    }

    /** Per-seller line items assigned to this user as the seller. */
    public function salesItems(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'seller_id');
    }

    public function buyerConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'buyer_id');
    }

    public function sellerConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'seller_id');
    }

    public function unreadSellerMessageCount(): int
    {
        return (int) $this->sellerConversations()->sum('seller_unread');
    }

    public function unreadBuyerMessageCount(): int
    {
        return (int) $this->buyerConversations()->sum('buyer_unread');
    }

    /** Stores (seller users) this user follows. */
    public function followedSellers(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'seller_followers', 'user_id', 'seller_id')->withTimestamps();
    }

    /** Buyers following this seller. */
    public function storeFollowers(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'seller_followers', 'seller_id', 'user_id')->withTimestamps();
    }

    public function boosts(): HasMany
    {
        return $this->hasMany(\App\Models\Boost::class, 'seller_id');
    }

    public function promoLinks(): HasMany
    {
        return $this->hasMany(\App\Models\PromoLink::class, 'seller_id');
    }

    public function productTemplates(): HasMany
    {
        return $this->hasMany(\App\Models\ProductTemplate::class, 'seller_id');
    }

    public function bundles(): HasMany
    {
        return $this->hasMany(\App\Models\Bundle::class, 'seller_id');
    }

    public function affiliateAccounts(): HasMany
    {
        return $this->hasMany(\App\Models\Affiliate::class);
    }

    public function storeLoyaltyAccounts(): HasMany
    {
        return $this->hasMany(\App\Models\StoreLoyaltyAccount::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(\App\Models\Subscription::class, 'buyer_id');
    }

    public function isPlusMember(): bool
    {
        return (bool) $this->plus_until && $this->plus_until->isFuture();
    }

    /** Team members this user (a store owner) has invited. */
    public function teamMembers(): HasMany
    {
        return $this->hasMany(\App\Models\SellerTeamMember::class, 'owner_id');
    }

    /** Stores this user can operate as a team member. */
    public function sellerTeamSeats(): HasMany
    {
        return $this->hasMany(\App\Models\SellerTeamMember::class, 'user_id');
    }

    public function activeSellerSeat(): ?\App\Models\SellerTeamMember
    {
        return $this->sellerTeamSeats()
            ->where('status', 'active')
            ->whereHas('owner.sellerProfile', fn ($q) => $q->where('status', 'approved'))
            ->first();
    }

    public function offersMade(): HasMany
    {
        return $this->hasMany(\App\Models\Offer::class, 'buyer_id');
    }

    public function offersReceived(): HasMany
    {
        return $this->hasMany(\App\Models\Offer::class, 'seller_id');
    }

    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    public function referralsMade(): HasMany
    {
        return $this->hasMany(Referral::class, 'referrer_id');
    }

    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class);
    }

    public function wishlist(): HasOne
    {
        return $this->hasOne(Wishlist::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }
}
