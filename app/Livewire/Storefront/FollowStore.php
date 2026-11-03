<?php

namespace App\Livewire\Storefront;

use App\Models\User;
use Livewire\Component;

class FollowStore extends Component
{
    public int $sellerId;

    public bool $following = false;

    public int $count = 0;

    public function mount(): void
    {
        $this->count = User::whereHas('followedSellers', fn ($q) => $q->where('seller_id', $this->sellerId))->count();
        $this->following = auth()->check()
            && auth()->user()->followedSellers()->where('seller_id', $this->sellerId)->exists();
    }

    public function toggle(): void
    {
        if (! auth()->check()) {
            $this->redirect(route('login'));

            return;
        }
        if ($this->sellerId === auth()->id()) {
            return;
        }

        if ($this->following) {
            auth()->user()->followedSellers()->detach($this->sellerId);
            $this->count = max(0, $this->count - 1);
        } else {
            auth()->user()->followedSellers()->syncWithoutDetaching([$this->sellerId]);
            $this->count++;
        }

        $this->following = ! $this->following;
    }

    public function render()
    {
        return view('livewire.storefront.follow-store');
    }
}
