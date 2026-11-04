<section data-reveal>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h2 class="text-lg font-bold">Questions &amp; answers</h2>
        @unless ($showForm)
            <button wire:click="$set('showForm', true)" class="btn-outline btn-sm">Ask a question</button>
        @endunless
    </div>

    @if ($showForm)
        <form wire:submit="ask" class="mt-5 card space-y-4 p-5">
            <div>
                <label class="label" for="q-body">Your question</label>
                <textarea id="q-body" wire:model="questionBody" rows="3" class="input mt-1" placeholder="e.g. Does this fit a 2019 Civic EX with the 1.5T engine?"></textarea>
                @error('questionBody') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
            </div>
            @guest
                <div>
                    <label class="label" for="q-name">Your name <span class="text-muted">(optional)</span></label>
                    <input id="q-name" type="text" wire:model="guestName" class="input mt-1" maxlength="80" placeholder="Guest">
                    @error('guestName') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
            @endguest
            <div class="flex gap-2">
                <button type="submit" class="btn-accent btn-sm">Post question</button>
                <button type="button" wire:click="$set('showForm', false)" class="btn-ghost btn-sm">Cancel</button>
            </div>
        </form>
    @endif

    <div class="mt-6 space-y-5">
        @forelse ($questions as $question)
            <article wire:key="q-{{ $question->id }}" class="border-b border-line pb-5 last:border-0">
                <div class="flex gap-2">
                    <span class="mt-0.5 text-sm font-bold text-accent">Q</span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium">{{ $question->body }}</p>
                        <p class="mt-1 text-xs text-muted">
                            {{ $question->user?->name ?? $question->author_name ?? 'Guest' }} · {{ $question->created_at->diffForHumans() }}
                        </p>

                        @foreach ($question->answers as $answer)
                            <div wire:key="a-{{ $answer->id }}" class="mt-3 flex gap-2 rounded-xl bg-surface-2 p-3">
                                <span class="mt-0.5 text-sm font-bold text-muted">A</span>
                                <div class="min-w-0">
                                    <p class="text-sm">{{ $answer->body }}</p>
                                    <p class="mt-1 text-xs text-muted">
                                        {{ $answer->user?->name ?? $answer->author_name ?? 'Panmotive' }}
                                        @if ($answer->is_seller)
                                            <span class="pill ml-1 !bg-surface-2 !text-fg">Seller</span>
                                        @endif
                                        · {{ $answer->created_at->diffForHumans() }}
                                    </p>
                                </div>
                            </div>
                        @endforeach

                        @auth
                            @if ($answeringId === $question->id)
                                <div class="mt-3">
                                    <textarea wire:model="answerBody.{{ $question->id }}" rows="2" class="input" placeholder="Share what you know…"></textarea>
                                    @error('answerBody.'.$question->id) <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                                    <div class="mt-2 flex gap-2">
                                        <button wire:click="answer({{ $question->id }})" class="btn-accent btn-sm">Post answer</button>
                                        <button wire:click="$set('answeringId', null)" class="btn-ghost btn-sm">Cancel</button>
                                    </div>
                                </div>
                            @else
                                <button wire:click="startAnswer({{ $question->id }})" class="mt-2 text-xs font-medium text-accent hover:underline">Answer this</button>
                            @endif
                        @endauth
                    </div>
                </div>
            </article>
        @empty
            <div class="card grid place-items-center p-10 text-center text-sm text-muted">
                <x-ui.icon name="mail" class="w-8 h-8" />
                <p class="mt-2 font-medium text-fg">No questions yet</p>
                <p>Ask the seller anything about fitment, condition, or shipping.</p>
            </div>
        @endforelse
    </div>

    @if ($questions->hasPages())
        <div class="mt-6">{{ $questions->links() }}</div>
    @endif
</section>
