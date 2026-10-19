<?php

namespace FlutterSdk\MagicStarter\Http\Controllers;

use FlutterSdk\MagicStarter\Contracts\SearchesSupportArticles;
use FlutterSdk\MagicStarter\Enums\SupportAudience;
use FlutterSdk\MagicStarter\Http\Requests\SearchSupportArticlesRequest;
use FlutterSdk\MagicStarter\Http\Resources\SupportArticleSuggestionResource;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportArticleSearch;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * In-app article suggestions for a signed-in customer who is writing to support.
 */
class SupportArticleController
{
    /**
     * The best matches for what the customer has typed, at most five.
     *
     * Searched as the public page's audience, not the customer's: a suggestion
     * links to that page, so an `ai_only` article it cannot show is never offered.
     */
    public function index(SearchSupportArticlesRequest $request): AnonymousResourceCollection
    {
        $locale = (string) ($request->validated('locale') ?? config('magic-starter.defaults.locale'));
        $articles = app(SearchesSupportArticles::class)->search(
            (string) $request->validated('search'),
            SupportAudience::PAGE,
            $locale,
            SupportArticleSearch::MAX_RESULTS,
        );

        // Each entry carries the locale it was searched in, so its text and link are read in the same one.
        return SupportArticleSuggestionResource::collection(array_map(
            static fn (array $article): array => [...$article, 'locale' => $locale],
            $articles,
        ));
    }
}
