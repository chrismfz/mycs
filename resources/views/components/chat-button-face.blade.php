{{--
    What MyCS's own «Chat» buttons show (Settings → livechat_button_style, owner 2026-11-17): the text, our icon and the
    text, or the icon alone (the text then its accessible label). The icon is our fixed file (ChatLauncher::icon), never
    anyone's upload.
--}}
@php $look = \App\Support\ChatLauncher::ownStyle(); @endphp
@if ($look !== 'text')
{!! \App\Support\ChatLauncher::icon() !!}
@endif
@if ($look === 'icon')
<span class="sr-only">{{ __('Chat') }}</span>
@else
<span>{{ __('Chat') }}</span>
@endif
