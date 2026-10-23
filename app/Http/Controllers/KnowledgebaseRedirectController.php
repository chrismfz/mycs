<?php

namespace App\Http\Controllers;

use App\Models\KbArticle;
use App\Models\KbCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * WHMCS's other addresses of its knowledgebase, redirected for good (301) to ours, which are its own (docs/knowledgebase.md):
 * /clients/knowledgebase/... (myip's WHMCS lived under /clients), knowledgebase.php?action=..., and /knowledgebase/{id}.
 * Only to our own addresses: a path is never sent anywhere else.
 */
class KnowledgebaseRedirectController
{
    public function clients(?string $path = null): RedirectResponse
    {
        $path = trim((string) $path, '/');
        if (preg_match('#^tag/([^/]{1,200})$#', $path, $m)) {
            return redirect()->route('kb.tag', ['tag' => $m[1]], 301);
        }
        if (preg_match('#^(\d+)(?:/([^/]*))?$#', $path, $m)) {
            // WHMCS: an article ends in .html, a category does not; ours resolves the id and its own slug.
            $article = str_ends_with($m[2] ?? '', '.html') || !isset($m[2]);

            return $this->to((int) $m[1], $article);
        }

        return redirect()->route('kb.index', [], 301);
    }

    /** /knowledgebase/{id}: WHMCS opened an article by its id alone. */
    public function id(string $id): RedirectResponse
    {
        // Digits past an int (ADV-5) are no article: the knowledgebase's page.
        return strlen($id) > 18 ? redirect()->route('kb.index', [], 301) : $this->to((int) $id, true);
    }

    /** knowledgebase.php?action=displayarticle&id=…, displaycat&catid=…, search&search=… (WHMCS's addresses without rewrite). */
    public function script(Request $request): RedirectResponse
    {
        // Only text: an array (?action[]=) is no address of WHMCS's (ADV-4).
        $query = fn (string $key) => is_string($value = $request->query($key)) ? $value : '';

        return match ($query('action')) {
            'displayarticle' => $this->to((int) $query('id'), true),
            'displaycat' => $this->to((int) $query('catid'), false),
            'search' => redirect()->route('kb.index', array_filter(['q' => mb_substr($query('search'), 0, 200)]), 301),
            default => redirect()->route('kb.index', [], 301),
        };
    }

    /** The article (or the category) of this id, else the other kind, else the knowledgebase's page. */
    private function to(int $id, bool $article): RedirectResponse
    {
        $found = $article
            ? (KbArticle::query()->published()->find($id) ?? KbCategory::query()->find($id))
            : (KbCategory::query()->find($id) ?? KbArticle::query()->published()->find($id));

        return redirect()->to($found?->url() ?? route('kb.index'), 301);
    }
}
