<div class="container mt-14">
    <x-navigation.breadcrumb />
    <div class="px-2">
        <h4 class="text-2xl font-bold pb-1">{{ __('account.emails.title') }}</h4>
        <p class="text-sm text-base/60 pb-4">{{ __('account.emails.intro') }}</p>

        @if ($email)
        {{-- The mail as it was sent, in a sandbox: no script of it runs, its links open in a new tab. --}}
        <div class="mb-6 bg-background-secondary border border-neutral rounded-lg">
            <div class="flex flex-wrap items-start justify-between gap-2 px-4 py-3 border-b border-neutral">
                <div class="min-w-0">
                    <h5 class="font-semibold break-words">{{ $email->subject }}</h5>
                    <p class="text-sm text-base/60">
                        {{ __('account.emails.to') }} {{ $email->to }} · {{ ($email->sent_at ?? $email->created_at)->format('d/m/Y H:i') }}
                    </p>
                </div>
                <button type="button" wire:click="close" class="text-sm text-secondary hover:underline">{{ __('account.emails.close') }}</button>
            </div>
            <iframe sandbox="allow-popups allow-popups-to-escape-sandbox" title="{{ $email->subject }}"
                srcdoc="{{ '<base target="_blank">' . $email->body }}" class="w-full bg-white rounded-b-lg" style="height: 70vh; border: 0;"></iframe>
        </div>
        @elseif ($open !== null)
        <p class="mb-6 text-sm text-base/60">{{ __('account.emails.not_found') }}</p>
        @endif

        @if ($emails->isEmpty())
        <p>{{ __('account.emails.none') }}</p>
        @else
        <div class="overflow-x-auto bg-background-secondary border border-neutral rounded-lg">
            <table class="w-full text-sm text-left">
                <thead class="text-base/60">
                    <tr>
                        <th class="px-4 py-2 whitespace-nowrap">{{ __('account.emails.date') }}</th>
                        <th class="px-4 py-2">{{ __('account.emails.subject') }}</th>
                        <th class="px-4 py-2 hidden md:table-cell">{{ __('account.emails.to') }}</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($emails as $row)
                    <tr wire:key="email-{{ $row->id }}" @class(['border-t border-neutral', 'bg-secondary/10' => $row->id === $email?->id])>
                        <td class="px-4 py-2 whitespace-nowrap text-base/70">{{ ($row->sent_at ?? $row->created_at)->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-2 break-words">{{ $row->subject }}</td>
                        <td class="px-4 py-2 hidden md:table-cell text-base/70">{{ $row->to }}</td>
                        <td class="px-4 py-2 text-right">
                            <button type="button" wire:click="show({{ $row->id }})" class="text-secondary hover:underline whitespace-nowrap">{{ __('account.emails.view') }}</button>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $emails->links() }}</div>
        @endif
    </div>
</div>
