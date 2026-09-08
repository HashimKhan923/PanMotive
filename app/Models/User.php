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
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
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
}
