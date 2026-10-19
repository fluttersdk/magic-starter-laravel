<?php

namespace FlutterSdk\MagicStarter\Contracts;

/**
 * A host's own values for the placeholder tokens its knowledge-base articles carry.
 *
 * OPTIONAL, and shipped with no binding: an article may name a figure the host
 * computes (a plan limit, a region count) as a token instead of typing it, and
 * only the host knows the value. With nothing bound the text is read as written.
 *
 * {@see \FlutterSdk\MagicStarter\Support\SupportAi\Tools\SupportArticleText} asks
 * before it reduces the Markdown to text, so every reader the package serves (the
 * FAQ tools the model calls and the in-app suggestions) sees the values, and a
 * value written in Markdown is reduced like the rest of the article.
 */
interface ResolvesSupportArticlePlaceholders
{
    /**
     * The Markdown with every token the host knows replaced by its value; unknown tokens are left as written.
     *
     * @param  string  $locale  The language the article is read in.
     */
    public function resolve(string $markdown, string $locale): string;
}
