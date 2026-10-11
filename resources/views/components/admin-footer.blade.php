@php
    // The deployed commit, written by deploy/update.sh (absent on a plain checkout).
    $build = json_decode(@file_get_contents(storage_path('app/build.json')) ?: 'null', true);
@endphp
<div class="ring-1 ring-gray-950/5 dark:ring-white/10">
</div>

<span class="text-xs text-gray-400 mt-auto">
    MyCS @if ($build) · {{ $build['sha'] ?? '' }} @endif
</span>
