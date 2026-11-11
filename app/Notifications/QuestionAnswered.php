<?php

namespace App\Notifications;

use App\Models\Answer;
use App\Notifications\Concerns\QueuesEmail;
use App\Notifications\Messages\PanmotiveMail;
use App\Support\EmailPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** Tells a shopper the seller answered the question they asked. */
class QuestionAnswered extends Notification implements ShouldQueue
{
    use Queueable, QueuesEmail;

    public function __construct(public Answer $answer) {}

    public function via(object $notifiable): array
    {
        return ($notifiable->email ?? null) && EmailPreferences::wants($notifiable, 'questions') ? ['mail', 'database'] : ['database'];
    }

    public function toMail(object $notifiable): PanmotiveMail
    {
        $question = $this->answer->question;
        $product = $question->product;
        $store = $product->seller?->sellerProfile?->business_name ?? 'The seller';

        return PanmotiveMail::to($notifiable)
            ->subject("{$store} answered your question")
            ->preheader(Str::limit($this->answer->body, 120))
            ->line('You asked a question about this part:')
            ->items([[
                'title' => $product->title,
                'price' => PanmotiveMail::money($product->effectivePrice()),
                'image' => $product->getFirstMediaUrl('images', 'thumb') ?: null,
                'url' => route('products.show', $product->slug),
            ]])
            ->quote(Str::limit($question->body, 400), 'You asked')
            ->quote(Str::limit($this->answer->body, 800), $store.' answered')
            ->action('See the listing', route('products.show', $product->slug).'#questions')
            ->optional($notifiable, 'questions');
    }

    public function toArray(object $notifiable): array
    {
        $product = $this->answer->question->product;

        return [
            'type' => 'question_answered',
            'title' => 'The seller answered your question',
            'body' => Str::limit($this->answer->body, 120),
            'url' => route('products.show', $product->slug).'#questions',
            'question_id' => $this->answer->question_id,
        ];
    }
}
