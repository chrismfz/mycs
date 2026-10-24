{{--
    SslCheck's results (docs/ssl-vault.md, PR 2), in the client area and the admin: one line each, its mark, its sentence
    (services.ssl.checks.{code}, or .{code}.{status} where the outcome changes the sentence) and its values.
--}}
@props(['results' => []])
<ul {{ $attributes->merge(['class' => 'flex flex-col gap-1 text-sm']) }}>
    @foreach($results as $result)
        @php
            $key = 'services.ssl.checks.' . $result['code'];
            $line = \Illuminate\Support\Facades\Lang::has($key . '.' . $result['status']) ? __($key . '.' . $result['status'], $result['values']) : __($key, $result['values']);
        @endphp
        <li class="grid grid-cols-[1.25rem_1fr] gap-x-2 items-baseline">
            @switch($result['status'])
                @case('ok') <span class="font-bold text-green-600" aria-label="ok">✓</span> @break
                @case('warn') <span class="font-bold text-yellow-600" aria-label="warning">!</span> @break
                @case('fail') <span class="font-bold text-red-600" aria-label="problem">✗</span> @break
                @default <span class="text-gray-400" aria-hidden="true">·</span>
            @endswitch
            <span class="min-w-0 break-words">{{ $line }}</span>
        </li>
    @endforeach
</ul>
