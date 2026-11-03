<?php

namespace App\Livewire\Storefront;

use App\Models\Product;
use App\Services\MessagingService;
use Livewire\Attributes\Validate;
use Livewire\Component;

class MessageSeller extends Component
{
    public Product $product;

    public bool $open = false;

    public bool $sent = false;

    #[Validate('required|string|min:5|max:2000')]
    public string $body = '';

    public function send(MessagingService $messaging): void
    {
        abort_unless(auth()->check(), 403);
        $this->validate();

        $seller = $this->product->seller;
        abort_unless($seller && $seller->id !== auth()->id(), 403);

        $conversation = $messaging->threadFor(auth()->user(), $seller, $this->product);
        $messaging->send($conversation, auth()->user(), $this->body);

        $this->reset('body', 'open');
        $this->sent = true;
        $this->dispatch('toast', message: 'Message sent to the seller');
    }

    public function render()
    {
        return view('livewire.storefront.message-seller');
    }
}
