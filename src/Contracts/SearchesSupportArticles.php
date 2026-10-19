<?php

namespace FlutterSdk\MagicStarter\Contracts;

use FlutterSdk\MagicStarter\Enums\SupportAudience;

/**
 * Ranks the knowledge base against a question, for one audience in one locale.
 *
 * The package binds the PHP scorer {@see \FlutterSdk\MagicStarter\Support\SupportAi\SupportArticleSearch};
 * a host with an embedding index binds its own. Whatever ranks, only articles
 * the audience may read are returned, so a staff-only article never reaches a
 * customer however the question is worded.
 */
interface SearchesSupportArticles
{
    /**
     * The best matching articles, best first, at most `$limit` and never more than five.
     *
     * An article without approved text in `$locale` is read in the default
     * locale instead. A question with no searchable word finds nothing. The
     * body of each entry is the article's Markdown, as staff wrote it.
     *
     * @param  string  $query  As the model or the customer wrote it: untrusted.
     * @return list<array{id: string|int, slug: string, section: string, question: string, body: string}>
     */
    public function search(string $query, SupportAudience $audience, string $locale, int $limit): array;
}
