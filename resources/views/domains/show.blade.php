<div class="container mt-14 space-y-4">
    <x-navigation.breadcrumb :trail="[['name' => __('navigation.domains'), 'url' => route('domains')], ['name' => $domain->fqdn]]" />

    @if ($openInvoice)
    <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-900 dark:bg-yellow-600/20 dark:text-yellow-200 p-4 rounded-lg">
        ⚠️ {{ __('domains.portal.open_invoice') }}
        <a href="{{ route('invoices.show', $openInvoice) }}" wire:navigate class="underline underline-offset-2">{{ __('services.view_and_pay') }}</a>.
    </div>
    @endif

    <div class="bg-background-secondary border border-neutral p-6 rounded-lg">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">{{ $domain->fqdn }}</h1>
                {{-- What the world sees at its registry (the public whois, rate limited and cached): G6 of grepp. --}}
                <a href="{{ route('domain.whois', ['domain' => $domain->fqdn]) }}" target="_blank" rel="noopener" class="text-sm underline underline-offset-2 text-base/70 mr-2">{{ __('domains.portal.whois') }}</a>
                <span class="inline-block mt-1 px-2 py-0.5 rounded-md text-xs font-medium
                    @if ($domain->status === \App\Enums\DomainStatus::Active) text-success bg-success/20
                    @elseif ($domain->status->isTerminal()) text-inactive bg-inactive/20
                    @else text-warning bg-warning/20 @endif">{{ $domain->status->getLabel() }}</span>
            </div>
            @if ($renewable)
            @if ($openInvoice)
            <x-button.primary class="!w-fit" wire:click="renew" wire:loading.attr="disabled">{{ __('domains.portal.pay_renewal') }}</x-button.primary>
            @else
            <x-button.primary class="!w-fit" wire:click="renew" wire:loading.attr="disabled" wire:confirm="{{ __('domains.portal.renew_confirm') }}">{{ __('domains.portal.renew') }}</x-button.primary>
            @endif
            @endif
        </div>

        <div class="grid md:grid-cols-2 gap-6 mt-6">
            {{-- Dates and renewal --}}
            <div class="space-y-1 text-base">
                <h4 class="text-lg font-semibold mb-2">{{ __('domains.portal.details') }}</h4>
                <div><span class="mr-2">{{ __('domains.portal.registered') }}:</span><span class="text-base/50">{{ $domain->registered_at?->format('d/m/Y') ?? '—' }}</span></div>
                <div><span class="mr-2">{{ __('domains.portal.expires') }}:</span><span class="text-base/50">{{ $domain->expires_at?->format('d/m/Y') ?? '—' }}</span></div>
                @if (in_array($domain->status, [\App\Enums\DomainStatus::Grace, \App\Enums\DomainStatus::Redemption], true))
                <div class="text-warning">{{ __('domains.portal.recoverable_until', ['date' => \App\Services\Domains\DomainReminders::lastDay($domain)->format('d/m/Y')]) }}</div>
                @endif
                <div class="flex items-center gap-2">
                    <span>{{ __('domains.portal.auto_renew') }}:</span>
                    <span class="text-base/50">{{ $domain->auto_renew ? __('domains.portal.on') : __('domains.portal.off_do_not_renew') }}</span>
                    @if ($canManage)
                    <button type="button" class="text-sm underline" wire:click="toggleAutoRenew"
                        wire:confirm="{{ $domain->auto_renew ? __('domains.portal.auto_renew_off_confirm') : __('domains.portal.auto_renew_on_confirm') }}">
                        {{ $domain->auto_renew ? __('domains.portal.turn_off') : __('domains.portal.turn_on') }}
                    </button>
                    @endif
                </div>
                <div class="flex items-center gap-2">
                    <span>{{ __('domains.portal.renewal_period') }}:</span>
                    @if (count($yearOptions) > 1)
                    <select class="bg-background-secondary border border-neutral rounded-md px-2 py-1 text-sm"
                        wire:change="setRenewalYears($event.target.value)" wire:loading.attr="disabled" aria-label="{{ __('domains.portal.renewal_period') }}">
                        @foreach ($yearOptions as $option => $label)
                        <option value="{{ $option }}" @selected($option === max(1, (int) $domain->years))>{{ $label }}</option>
                        @endforeach
                    </select>
                    @else
                    <span class="text-base/50">{{ trans_choice('domains.portal.years_option', max(1, (int) $domain->years), ['years' => max(1, (int) $domain->years)]) }}</span>
                    @endif
                </div>
            </div>

            {{-- At the registrar --}}
            <div class="space-y-1 text-base">
                <h4 class="text-lg font-semibold mb-2">{{ __('domains.portal.registrar') }}</h4>
                <div class="flex items-center gap-2">
                    <span>{{ __('domains.portal.transfer_lock') }}:</span>
                    <span class="text-base/50">{{ $domain->transfer_lock ? __('domains.portal.on') : __('domains.portal.off') }}</span>
                    @if ($lockable)
                    <button type="button" class="text-sm underline" wire:click="toggleTransferLock"
                        @if ($domain->transfer_lock) wire:confirm="{{ __('domains.portal.unlock_confirm') }}" @endif>{{ $domain->transfer_lock ? __('domains.portal.turn_off') : __('domains.portal.turn_on') }}</button>
                    @endif
                </div>
                @if ($privacyOffered || $domain->id_protection)
                <div class="flex items-center gap-2">
                    <span>{{ __('domains.portal.id_protection') }}:</span>
                    <span class="text-base/50">{{ $domain->id_protection ? __('domains.portal.on') : __('domains.portal.off') }}</span>
                    @if ($privacyOffered)
                    <button type="button" class="text-sm underline" wire:click="toggleIdProtection">{{ $domain->id_protection ? __('domains.portal.turn_off') : __('domains.portal.turn_on') }}</button>
                    @endif
                </div>
                @endif
                @if ($showEpp && $emailsAuthCode)
                {{-- The .gr registry never gives the code: it emails the owner a link to reset it (DACoR). --}}
                <div class="flex items-center gap-2">
                    <span>{{ __('domains.portal.epp_code') }}:</span>
                    <button type="button" class="text-sm underline" wire:click="emailAuthCode" wire:loading.attr="disabled"
                        wire:confirm="{{ __('domains.portal.auth_code_email_confirm') }}">{{ __('domains.portal.auth_code_email') }}</button>
                </div>
                <p class="text-xs text-base/50">{{ __('domains.portal.auth_code_email_help') }}</p>
                @elseif ($showEpp)
                {{-- Not in the page: «Show» asks the registrar for it (revealEppCode); wire:ignore keeps it across re-renders. --}}
                <div class="flex items-center gap-2" x-data="{ shown: false, code: null }" wire:ignore>
                    <span>{{ __('domains.portal.epp_code') }}:</span>
                    <span class="text-base/50 select-all font-mono break-all" x-show="shown" x-cloak x-text="code"></span>
                    <span class="text-base/50" x-show="!shown">••••••••</span>
                    <button type="button" class="text-sm underline"
                        x-on:click="if (!shown && code === null) { code = await $wire.revealEppCode(); } shown = !shown && code !== null"
                        x-text="shown ? @js(__('services.hide')) : @js(__('services.show'))"></button>
                </div>
                <p class="text-xs text-base/50">{{ __('domains.portal.epp_help') }}</p>
                @elseif ($canManage)
                <p class="text-sm text-base/60">{{ __('domains.portal.not_manageable') }}</p>
                @endif
            </div>
        </div>

        {{-- Nameservers --}}
        <div class="mt-6">
            <div class="flex items-center gap-3 mb-2">
                <h4 class="text-lg font-semibold">{{ __('domains.portal.nameservers') }}</h4>
                @if ($manageable && !$editNameservers)
                <button type="button" class="text-sm underline" wire:click="$set('editNameservers', true)">{{ __('domains.portal.change') }}</button>
                @endif
            </div>
            @if ($editNameservers)
            <form wire:submit="saveNameservers" class="space-y-2 max-w-md">
                @foreach ($nameservers as $i => $ns)
                <div class="flex gap-2">
                    <x-form.input name="nameservers.{{ $i }}.host" wire:model="nameservers.{{ $i }}.host" placeholder="ns{{ $i + 1 }}.example.com" />
                    @if (count($nameservers) > 2)
                    <button type="button" class="text-sm underline" wire:click="removeNameserver({{ $i }})">{{ __('domains.portal.remove') }}</button>
                    @endif
                </div>
                @endforeach
                @error('nameservers') <p class="text-sm text-error">{{ $message }}</p> @enderror
                <div class="flex gap-3">
                    @if (count($nameservers) < 13)
                    <button type="button" class="text-sm underline" wire:click="addNameserver">{{ __('domains.portal.add_nameserver') }}</button>
                    @endif
                    <x-button.primary class="!w-fit" type="submit" wire:loading.attr="disabled">{{ __('domains.portal.save') }}</x-button.primary>
                    <button type="button" class="text-sm underline" wire:click="$set('editNameservers', false)">{{ __('domains.portal.cancel') }}</button>
                </div>
            </form>
            @else
            <ul class="text-base/60 font-mono text-sm space-y-0.5">
                @forelse ($domain->nameservers as $ns)
                <li>{{ $ns->host }}</li>
                @empty
                <li>—</li>
                @endforelse
            </ul>
            @endif
        </div>

        {{-- Child nameservers (P5): ns1.example.gr with its IPs, kept at the registry as its own object --}}
        @if ($glue)
        <div class="mt-6">
            <div class="flex flex-wrap items-center gap-3 mb-2">
                <h4 class="text-lg font-semibold">{{ __('domains.portal.glue') }}</h4>
                <button type="button" class="text-sm underline" wire:click="readChildHosts" wire:loading.attr="disabled">{{ __('domains.portal.glue_read_now') }}</button>
                @if ($childHostsReadAt !== null && $glueEditing === null)
                <button type="button" class="text-sm underline" wire:click="startChildHost('')">{{ __('domains.portal.glue_add') }}</button>
                @endif
            </div>
            <p class="text-sm text-base/60 mb-2">{{ __('domains.portal.glue_help', ['domain' => $domain->fqdn]) }}</p>
            @if ($childHostsReadAt === null)
            <p class="text-sm text-base/60">{{ __('domains.portal.glue_not_read') }}</p>
            @else
            <p class="text-xs text-base/50 mb-2">{{ __('domains.portal.glue_read_at', ['at' => $childHostsReadAt]) }}@if ($childHostsStale) · <span class="text-warning">{{ __('domains.portal.glue_stale') }}</span>@endif</p>
            <ul class="text-sm space-y-1">
                @forelse ($childHosts as $host)
                <li class="flex flex-wrap items-center gap-x-3" wire:key="glue-{{ $host['name'] }}">
                    <span class="font-mono break-all">{{ $host['name'] }}</span>
                    <span class="font-mono break-all text-base/60">{{ implode(', ', (array) ($host['addresses'] ?? [])) ?: '—' }}</span>
                    @if ($glueEditing === null && ($host['read'] ?? true))
                    <button type="button" class="underline" wire:click="startChildHost(@js($host['name']))">{{ __('domains.portal.change') }}</button>
                    @endif
                    @if ($glueEditing === null && !($host['linked'] ?? true))
                    <button type="button" class="underline text-error" wire:click="deleteChildHost(@js($host['name']))"
                        wire:confirm="{{ __('domains.portal.glue_delete_confirm', ['host' => $host['name']]) }}">{{ __('domains.portal.remove') }}</button>
                    @endif
                </li>
                @empty
                <li class="text-base/60">{{ __('domains.portal.glue_none') }}</li>
                @endforelse
            </ul>
            @endif
            @if ($glueEditing !== null)
            <form wire:submit="saveChildHost" class="space-y-2 max-w-md mt-3">
                <x-form.input name="glueHost" wire:model="glueHost" :label="__('domains.portal.glue_host')" :placeholder="'ns1.' . $domain->fqdn" :disabled="$glueEditing !== ''" />
                <x-form.textarea name="glueAddresses" wire:model="glueAddresses" :label="__('domains.portal.glue_addresses')" rows="3" />
                <p class="text-xs text-base/60">{{ __('domains.portal.glue_addresses_help') }}</p>
                <div class="flex gap-3">
                    <x-button.primary class="!w-fit" type="submit" wire:loading.attr="disabled">{{ __('domains.portal.save') }}</x-button.primary>
                    <button type="button" class="text-sm underline" wire:click="cancelChildHost">{{ __('domains.portal.cancel') }}</button>
                </div>
            </form>
            @endif
        </div>
        @endif

        {{-- At the .gr registry (P2): its statuses and the EETT's in words, as the sync last read them; nothing internal. --}}
        @if ($registry)
        <div class="mt-6">
            <h4 class="text-lg font-semibold mb-2">{{ __('domains.portal.at_registry') }}</h4>
            <dl class="text-sm space-y-1">
                <div class="flex gap-2"><dt>{{ __('domains.portal.registry_status') }}:</dt><dd class="text-base/60">{{ implode(' · ', $registry['statuses']) }}</dd></div>
                @if ($registry['regulator'] !== [])
                {{-- Each already says «EETT: …» (review 3 of #220): no label of its own. --}}
                <div class="text-warning">{{ implode(' · ', $registry['regulator']) }}</div>
                @endif
                @if ($registry['forms'])
                <div class="flex gap-2"><dt>{{ __('domains.portal.forms') }}:</dt><dd class="text-base/60">{{ $registry['forms'] }}</dd></div>
                @endif
                @if ($registry['updated'])
                <div class="flex gap-2"><dt>{{ __('domains.portal.registry_updated') }}:</dt><dd class="text-base/60">{{ $registry['updated'] }}</dd></div>
                @endif
            </dl>
        </div>
        @endif

        {{-- Contacts (P1): each role from the account's contacts; a .gr holder is changed only by us (a charged act). --}}
        <div class="mt-6">
            <div class="flex items-center gap-3 mb-2">
                <h4 class="text-lg font-semibold">{{ __('domains.portal.contacts') }}</h4>
                @if ($manageable && !$editContacts)
                <button type="button" class="text-sm underline" wire:click="$set('editContacts', true)">{{ __('domains.portal.change') }}</button>
                @endif
            </div>
            @if ($editContacts)
            <form wire:submit="saveContacts" class="space-y-3 max-w-xl">
                @foreach (\App\Models\Domain::ROLES as $role)
                <div>
                    <label class="block text-sm mb-1" for="contact-{{ $role }}">{{ __('domains.role.' . $role) }}</label>
                    <select id="contact-{{ $role }}" wire:model="contactRoles.{{ $role }}" class="w-full bg-background-secondary border border-neutral rounded-md px-2 py-1 text-sm"
                        @disabled($role === 'registrant' && !$registrantChangeable)>
                        @if ($domain->{$role . '_contact_id'} === null)
                        <option value="">—</option>
                        @endif
                        @foreach ($contactOptions as $id => $label)
                        <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @if ($role === 'registrant' && !$registrantChangeable)
                    <p class="text-xs text-base/60 mt-1">{{ $registrantLocked }}</p>
                    @endif
                    @error('contactRoles.' . $role) <p class="text-sm text-error">{{ $message }}</p> @enderror
                </div>
                @endforeach
                @if ($seesContacts)
                <p class="text-xs text-base/60">{!! __('domains.portal.contacts_help', ['link' => '<a href="' . e(route('account.contacts')) . '" wire:navigate class="underline">' . e(__('domains.portal.account_contacts')) . '</a>']) !!}</p>
                @endif
                <div class="flex gap-3">
                    <x-button.primary class="!w-fit" type="submit" wire:loading.attr="disabled">{{ __('domains.portal.save') }}</x-button.primary>
                    <button type="button" class="text-sm underline" wire:click="$set('editContacts', false)">{{ __('domains.portal.cancel') }}</button>
                </div>
            </form>
            @else
            <dl class="grid sm:grid-cols-2 gap-x-6 gap-y-1 text-sm">
                @foreach (\App\Models\Domain::ROLES as $role)
                {{-- Only this account's: one left by a move from another account is never shown (ADV-F2 of #219). --}}
                @php($contact = $domain->{$role . 'Contact'}?->client_id === $domain->client_id ? $domain->{$role . 'Contact'} : null)
                <div class="flex gap-2">
                    <dt>{{ __('domains.role.' . $role) }}:</dt>
                    <dd class="text-base/60">{{ $contact ? trim(trim($contact->first_name . ' ' . $contact->last_name) . ($contact->company_name ? ' · ' . $contact->company_name : '')) : '—' }}</dd>
                </div>
                @endforeach
            </dl>
            @endif
        </div>

        {{-- DNSSEC (P3): DS records at the .gr registry, DNSKEYs elsewhere; read when asked, changed as the admin does. --}}
        @if ($dnssecKind)
        <div class="mt-6">
            <div class="flex items-center gap-3 mb-2">
                <h4 class="text-lg font-semibold">DNSSEC</h4>
                <button type="button" class="text-sm underline" wire:click="readDnssec" wire:loading.attr="disabled">{{ __('domains.portal.dnssec_read_now') }}</button>
                @if ($dnssec !== null && !$editDnssec)
                <button type="button" class="text-sm underline" wire:click="startDnssec">{{ __('domains.portal.change') }}</button>
                @endif
            </div>
            @if ($editDnssec)
            {{-- The confirmation on the element that runs it (ADV-1 of #223: on the button it was never asked). --}}
            <form wire:submit="saveDnssec" class="space-y-3"
                wire:confirm="{{ count($dnssecRows) === 0 ? __('domains.portal.dnssec_unsign_confirm') : __('domains.portal.dnssec_save_confirm') }}">
                <p class="text-sm text-warning">{{ $dnssecKind === \App\Support\Domains\DnssecRecord::DS ? __('domains.portal.dnssec_ds_help') : __('domains.portal.dnssec_dnskey_help') }}</p>
                @foreach ($dnssecRows as $i => $row)
                <div class="grid sm:grid-cols-4 gap-2 items-start border border-neutral rounded-md p-2" wire:key="dnssec-{{ $i }}">
                    @if ($dnssecKind === \App\Support\Domains\DnssecRecord::DS)
                    <x-form.input name="dnssecRows.{{ $i }}.key_tag" wire:model="dnssecRows.{{ $i }}.key_tag" placeholder="{{ __('domains.portal.dnssec_key_tag') }}" />
                    <select wire:model="dnssecRows.{{ $i }}.algorithm" class="bg-background-secondary border border-neutral rounded-md px-2 py-1 text-sm" aria-label="{{ __('domains.portal.dnssec_algorithm') }}">
                        <option value="">{{ __('domains.portal.dnssec_algorithm') }}</option>
                        @foreach (\App\Support\Domains\DnssecOptions::algorithms($dnssecKind, $row['algorithm'] ?? null) as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                    <select wire:model="dnssecRows.{{ $i }}.digest_type" class="bg-background-secondary border border-neutral rounded-md px-2 py-1 text-sm" aria-label="{{ __('domains.portal.dnssec_digest_type') }}">
                        <option value="">{{ __('domains.portal.dnssec_digest_type') }}</option>
                        @foreach (\App\Support\Domains\DnssecOptions::digestTypes($row['digest_type'] ?? null) as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                    <button type="button" class="text-sm underline justify-self-start" wire:click="removeDnssecRow({{ $i }})">{{ __('domains.portal.remove') }}</button>
                    <div class="sm:col-span-4"><x-form.input name="dnssecRows.{{ $i }}.digest" wire:model="dnssecRows.{{ $i }}.digest" placeholder="{{ __('domains.portal.dnssec_digest') }}" /></div>
                    @else
                    <select wire:model="dnssecRows.{{ $i }}.flags" class="bg-background-secondary border border-neutral rounded-md px-2 py-1 text-sm" aria-label="{{ __('domains.portal.dnssec_flags') }}">
                        <option value="">{{ __('domains.portal.dnssec_flags') }}</option>
                        @foreach (\App\Support\Domains\DnssecOptions::flags($row['flags'] ?? null) as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                    <select wire:model="dnssecRows.{{ $i }}.algorithm" class="bg-background-secondary border border-neutral rounded-md px-2 py-1 text-sm" aria-label="{{ __('domains.portal.dnssec_algorithm') }}">
                        <option value="">{{ __('domains.portal.dnssec_algorithm') }}</option>
                        @foreach (\App\Support\Domains\DnssecOptions::algorithms($dnssecKind, $row['algorithm'] ?? null) as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                    <span></span>
                    <button type="button" class="text-sm underline justify-self-start" wire:click="removeDnssecRow({{ $i }})">{{ __('domains.portal.remove') }}</button>
                    <div class="sm:col-span-4"><textarea wire:model="dnssecRows.{{ $i }}.public_key" rows="2" class="w-full bg-background-secondary border border-neutral rounded-md px-2 py-1 text-sm font-mono" placeholder="{{ __('domains.portal.dnssec_public_key') }}"></textarea></div>
                    @endif
                    @foreach (['key_tag', 'algorithm', 'digest_type', 'digest', 'flags', 'public_key'] as $field)
                    @error('dnssecRows.' . $i . '.' . $field) <p class="sm:col-span-4 text-sm text-error">{{ $message }}</p> @enderror
                    @endforeach
                </div>
                @endforeach
                <div class="flex gap-3">
                    @if (count($dnssecRows) < 16)
                    <button type="button" class="text-sm underline" wire:click="addDnssecRow">{{ __('domains.portal.dnssec_add') }}</button>
                    @endif
                    <x-button.primary class="!w-fit" type="submit" wire:loading.attr="disabled">{{ __('domains.portal.save') }}</x-button.primary>
                    <button type="button" class="text-sm underline" wire:click="$set('editDnssec', false)">{{ __('domains.portal.cancel') }}</button>
                </div>
            </form>
            @elseif ($dnssec === null)
            <p class="text-sm text-base/60">{{ __('domains.portal.dnssec_not_read') }}</p>
            @else
            <ul class="text-base/60 font-mono text-sm space-y-0.5 break-all">
                @forelse ($dnssec as $record)
                <li>{{ $record->label() }}</li>
                @empty
                <li class="font-sans">{{ __('domains.portal.dnssec_unsigned') }}</li>
                @endforelse
            </ul>
            @if ($dnssecChecking)
            <p class="text-xs text-base/60 mt-1">{{ __('domains.portal.dnssec_note', ['at' => $dnssecChecking]) }}</p>
            @endif
            @endif
        </div>
        @endif
    </div>
</div>
