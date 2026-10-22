{!! App\Helpers\TemplateRenderer::mailHeader() !!}
{{-- HTML already: a template's Markdown is made HTML before its values go in (App\Mail\Mail), never after. --}}
{!! $slot !!}
{!! App\Helpers\TemplateRenderer::mailFooter() !!}
