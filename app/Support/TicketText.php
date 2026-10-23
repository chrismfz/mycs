<?php

namespace App\Support;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;
use League\CommonMark\Util\RegexHelper;

/**
 * A ticket message as HTML (Markdown, its own HTML escaped, no unsafe links), as before, but an image never loads by
 * itself: whoever wrote `![](https://…)` would learn the reader's address, browser and time of reading (security audit
 * API-7). It is a link to the image instead, opened only if the reader clicks it.
 */
final class TicketText
{
    public static function html(string $markdown): string
    {
        $environment = new Environment(['html_input' => 'escape', 'allow_unsafe_links' => false, 'renderer' => ['soft_break' => '<br>']]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addRenderer(Image::class, new class implements NodeRendererInterface
        {
            public function render(Node $node, ChildNodeRendererInterface $childRenderer): HtmlElement
            {
                /** @var Image $node */
                $text = $childRenderer->renderNodes($node->children());

                // As CommonMark's own: `javascript:` and its kind are no link (allow_unsafe_links false).
                $url = RegexHelper::isLinkPotentiallyUnsafe($node->getUrl()) ? '' : $node->getUrl();

                return new HtmlElement('a', ['href' => $url, 'rel' => 'nofollow noopener noreferrer', 'target' => '_blank'],
                    $text !== '' ? '🖼 ' . $text : '🖼 ' . e($url));
            }
        }, 10);

        return (string) (new MarkdownConverter($environment))->convert($markdown);
    }
}
