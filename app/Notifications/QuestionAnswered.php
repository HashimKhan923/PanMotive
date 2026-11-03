<?php

namespace App\Notifications;

use App\Models\Answer;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Tells a shopper the seller answered the question they asked. */
class QuestionAnswered extends Notification
{
    use Queueable;

    public function __construct(public Answer $answer) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $product = $this->answer->question->product;

        return [
            'type' => 'question_answered',
            'title' => 'The seller answered your question',
            'body' => \Illuminate\Support\Str::limit($this->answer->body, 120),
            'url' => route('products.show', $product->slug).'#questions',
            'question_id' => $this->answer->question_id,
        ];
    }
}
