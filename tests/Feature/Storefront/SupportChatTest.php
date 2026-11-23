<?php

namespace Tests\Feature\Storefront;

use App\Livewire\Storefront\SupportChat;
use App\Models\AdminAlert;
use App\Notifications\AccountNotice;
use App\Services\AI\AiClient;
use App\Support\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class SupportChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_browser_cannot_rewrite_the_conversation(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(SupportChat::class)->set('messages', [5]);
    }

    public function test_asking_for_a_person_really_reaches_the_support_mailbox(): void
    {
        Notification::fake();
        $this->mock(AiClient::class)->shouldNotReceive('complete');

        Livewire::test(SupportChat::class)
            ->set('input', 'Can I talk to a real person about my order?')
            ->call('send')
            ->assertSet('handoff', true)
            ->call('requestHuman')
            ->assertHasErrors('email')
            ->set('email', 'shopper@example.com')
            ->call('requestHuman')
            ->assertHasNoErrors()
            ->assertSet('handedOff', true)
            ->assertSee('will reply to shopper@example.com');

        Notification::assertSentTo(new AnonymousNotifiable, AccountNotice::class, function ($n, $channels, $notifiable) {
            return $notifiable->routes['mail'] === MailSettings::supportEmail()
                && str_contains($n->body, 'shopper@example.com')
                && str_contains($n->body, 'talk to a real person');
        });
        $this->assertSame(1, AdminAlert::where('type', 'support_request')->count());
    }

    public function test_normal_questions_go_to_the_assistant(): void
    {
        $this->mock(AiClient::class)->shouldReceive('complete')->once()->andReturn('Returns are open for 30 days.');

        Livewire::test(SupportChat::class)
            ->set('input', 'How long do I have to return a part?')
            ->call('send')
            ->assertSee('Returns are open for 30 days.')
            ->assertSet('handoff', false);
    }
}
