<?php

namespace FlutterSdk\MagicStarter\Http\Resources;

use FlutterSdk\MagicStarter\Models\SupportArticle;
use FlutterSdk\MagicStarter\Support\SupportAi\Tools\SupportArticleText;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One knowledge-base article suggested to a customer, as plain text.
 *
 * Wraps an entry of {@see \FlutterSdk\MagicStarter\Contracts\SearchesSupportArticles::search()}. The Markdown body
 * never leaves: `magic_starter` has no Markdown renderer, so the app gets an
 * excerpt with images reduced to their alt text and links to their label, and
 * a `url` to the public page of the requested locale to read the whole article on.
 *
 * @property array{id: string|int, slug: string, section: string, question: string, body: string, locale: string} $resource
 */
class SupportArticleSuggestionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $text = app(SupportArticleText::class);
        $locale = $this->resource['locale'];

        return [
            'id' => $this->resource['id'],
            'slug' => $this->resource['slug'],
            'question' => $text->line($this->resource['question'], $locale),
            'excerpt' => $text->line($this->resource['body'], $locale),
            'url' => SupportArticle::publicUrl($this->resource['slug'], $locale),
        ];
    }
}
