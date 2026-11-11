<?php

namespace App\Notifications;

use App\Models\Question;
use App\Notifications\Concerns\QueuesEmail;
use App\Notifications\Messages\PanmotiveMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** Tells a seller a shopper asked a question on one of their listings. */
class NewProductQuestion extends Notification implements ShouldQueue
{
    use Queueable, QueuesEmail;

    public function __construct(public Question $question) {}

    public function via(object $notifiable): array
    {
        return $notifiable->email ? ['mail', 'database'] : ['database'];
    }

    public function toMail(object $notifiable): PanmotiveMail
    {
        $product = $this->question->product;

        return PanmotiveMail::to($notifiable)
            ->forSeller()
            ->subject('New question on '.Str::limit($product->title, 60))
            ->preheader(Str::limit($this->question->body, 120))
            ->line('A shopper asked a question about your listing:')
            ->items([[
                'title' => $product->title,
                'image' => $product->getFirstMediaUrl('images', 'thumb') ?: null,
                'url' => route('products.show', $product->slug),
            ]])
            ->quote(Str::limit($this->question->body, 600), 'A shopper asked')
            ->line('Your answer appears publicly on the listing — quick, specific answers (fitment, condition, what\'s included) win sales.')
            ->action('Answer the question', route('seller.questions.index', ['product' => $this->question->product_id]))
            ->line('Try to answer within '.config('marketplace.messages.question_target_hours', 48).' hours.')
            ->reason('You’re getting this email because you sell on Panmotive.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'question',
            'title' => 'New question on '.$this->question->product->title,
            'body' => Str::limit($this->question->body, 120),
            'url' => route('seller.questions.index'),
            'question_id' => $this->question->id,
        ];
    }
}
