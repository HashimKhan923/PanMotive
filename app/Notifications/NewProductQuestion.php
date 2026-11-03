<?php

namespace App\Notifications;

use App\Models\Question;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** Tells a seller a shopper asked a question on one of their listings. */
class NewProductQuestion extends Notification
{
    use Queueable;

    public function __construct(public Question $question) {}

    public function via(object $notifiable): array
    {
        return $notifiable->email ? ['mail', 'database'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New question on '.Str::limit($this->question->product->title, 60))
            ->line('A shopper asked:')
            ->line('"'.Str::limit($this->question->body, 400).'"')
            ->line('Your answer appears publicly on the listing — quick, specific answers (fitment, condition, what\'s included) win sales.')
            ->action('Answer the question', route('seller.questions.index', ['product' => $this->question->product_id]));
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
