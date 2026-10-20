<x-filament-panels::page>
    <div class="md:grid grid-cols-4 flex flex-col gap-4" wire:poll.10s>
        <div class="md:col-span-3">
            <div class="flex flex-col gap-4 max-h-[60vh] overflow-y-auto pr-4">
                @foreach ($this->record->messages()->with(['user', 'staff', 'attachments'])->get() as $index => $message)
                    <div
                        class="border border-gray-100 dark:border-gray-600 p-4 rounded-lg w-full max-w-[80%]  {{ $message->isFromStaff() ? 'mr-auto' : 'ml-auto' }}"
                        @if ($loop->last) x-data x-init="$el.scrollIntoView()" @endif>
                        <div class="flex justify-between gap-4">
                            <div class="flex items-center gap-4">
                                @if ($message->author())
                                    <x-filament-panels::avatar.user
                                        :user="$message->author()"
                                        class="size-7 shrink-0 rounded-full block"
                                    />
                                @endif
                                <div class="flex flex-col">
                                    <div class="flex gap-3 items-center">

                                        @if ($message->isFromStaff())
                                            <span class="text-lg font-semibold">{{ $message->staff?->name ?? __('Staff') }}</span>
                                        @elseif ($message->user)
                                            <a class="text-lg font-semibold hover:underline"
                                            href="{{ App\Admin\Resources\UserResource::getUrl('edit', ['record' => $message->user]) }}">
                                            {{ $message->user->name }}
                                        </a>
                                        @endif
                                </div>
                                <div class="flex gap-1">
                                    <p class="text-sm text-gray-500">
                                        {{ $message->created_at->diffForHumans() }}
                                    </p>
                                    @if($message->via === \App\Models\TicketMessage::VIA_EMAIL && filled($message->body_original))
                                        <x-filament::modal width="4xl">
                                            <x-slot name="trigger">
                                                <span class="text-sm text-gray-500 cursor-pointer hover:underline">
                                                    — {{ __('By email: the whole mail') }}
                                                </span>
                                            </x-slot>
                                            <pre class="whitespace-pre-wrap text-sm">{{ $message->body_original }}</pre>
                                        </x-filament::modal>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div>
                            @can('delete', $message)
                            <button wire:click="deleteMessage({{ $message->id }})"
                                class="dark:text-danger-300 text-danger-600 p-0">
                                Delete
                            </button>
                            @endcan
                        </div>
                    </div>
                    <div class="mt-2 prose dark:prose-invert break-words overflow-x-auto max-w-full">
                        {!! Str::markdown($message->message, [
                            'html_input' => 'escape',
                            'allow_unsafe_links' => false,
                            'renderer' => [
                                'soft_break' => "<br>"
                            ]
                        ]) !!}
                    </div>
                    <div class="flex flex-wrap gap-x-2">
                    @foreach($message->attachments as $attachment)
                        <div class="mt-2">
                            <a href="{{ route('filament.admin.ticket-attachments.show', $attachment) }}" class="text-sm rounded-md bg-gray-100 flex items-center dark:bg-gray-800 p-1 w-fit">
                                @if($attachment->canPreview())
                                    <img src="{{ route('filament.admin.ticket-attachments.show', $attachment) }}" alt="{{ $attachment->filename }}" class="max-w-full">
                                @else
                                    <x-ri-attachment-2 class="inline-block mr-1 size-4" />
                                    {{ $attachment->filename }}
                                @endif
                            </a>
                        </div>
                    @endforeach
                    </div>
                </div>
                @endforeach
            </div>
            <form wire:submit="send">
                <div class="mt-4 mb-4">
                    {{ $this->form }}
                </div>
                <div class="flex flex-wrap gap-2 justify-end">
                    @if ($this->linkToClient->isVisible())
                        {{ $this->linkToClient }}
                        {{ $this->revokeGuestLinks }}
                    @endif
                    @if ($this->closeTicket->isVisible())
                        {{ $this->closeTicket }}
                    @endif
                    
                    <x-filament::button type="submit" target="send">
                        Send Message
                    </x-filament::button>
                </div>
            </form>
        </div>

        <div class="md:order-last order-first w-full col-span-3 sm:col-auto">{{ $this->infolist }}</div>

    </div>
</x-filament-panels::page>
