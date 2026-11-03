<?php

namespace App\Services\Cart;

use App\Models\Product;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * DB-backed wishlist. One row per user or per guest session; the guest list is
 * merged into the user's list on the first authenticated resolve.
 */
class WishlistService
{
    private ?Wishlist $wishlist = null;

    public function current(): Wishlist
    {
        if ($this->wishlist) {
            return $this->wishlist;
        }

        if ($user = auth()->user()) {
            $list = Wishlist::firstOrCreate(['user_id' => $user->id]);
            $this->mergeGuest($list);

            return $this->wishlist = $list;
        }

        $sid = session('wishlist_session_id');
        if (! $sid) {
            session(['wishlist_session_id' => $sid = (string) Str::uuid()]);
        }

        return $this->wishlist = Wishlist::firstOrCreate(['session_id' => $sid]);
    }

    public function toggle(int $productId): bool
    {
        $list = $this->current();
        $existing = $list->items()->where('product_id', $productId)->first();

        if ($existing) {
            $existing->delete();

            return false;
        }

        WishlistItem::create([
            'wishlist_id' => $list->id,
            'product_id' => $productId,
            'price_at_add' => Product::whereKey($productId)->first()?->effectivePrice(),
        ]);

        return true;
    }

    public function remove(int $productId): void
    {
        $this->current()->items()->where('product_id', $productId)->delete();
    }

    public function has(int $productId): bool
    {
        return $this->current()->items()->where('product_id', $productId)->exists();
    }

    /** @return array<int,int> product ids */
    public function ids(): array
    {
        return $this->current()->items()->pluck('product_id')->all();
    }

    public function count(): int
    {
        return $this->existing()?->items()->count() ?? 0;
    }

    public function products(): Collection
    {
        return $this->current()->products()
            ->listed()
            ->with('brand', 'seller.sellerProfile')
            ->orderByPivot('created_at', 'desc')
            ->get();
    }

    private function existing(): ?Wishlist
    {
        if ($user = auth()->user()) {
            return $user->wishlist()->first();
        }
        $sid = session('wishlist_session_id');

        return $sid ? Wishlist::where('session_id', $sid)->first() : null;
    }

    private function mergeGuest(Wishlist $userList): void
    {
        $sid = session('wishlist_session_id');
        if (! $sid) {
            return;
        }

        $guest = Wishlist::where('session_id', $sid)->with('items')->first();
        if ($guest && $guest->id !== $userList->id) {
            foreach ($guest->items as $item) {
                WishlistItem::firstOrCreate(
                    ['wishlist_id' => $userList->id, 'product_id' => $item->product_id],
                    ['price_at_add' => $item->price_at_add],
                );
            }
            $guest->delete();
        }
        session()->forget('wishlist_session_id');
    }
}
