<?php

namespace FlutterSdk\MagicStarter\Support\SupportAi\Tools;

use FlutterSdk\MagicStarter\Contracts\SearchesSupportArticles;
use FlutterSdk\MagicStarter\Contracts\SupportTool;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportArticleSearch;
use Illuminate\Contracts\Foundation\Application;

/**
 * Lets the support AI find the knowledge-base articles that answer a question.
 *
 * The audience comes from {@see SupportToolContext::audience()} and the locale
 * from the app, which the host sets to the turn's locale; neither is an
 * argument, so the model can choose what to look for but never whose articles
 * it reads. A hit carries a short plain excerpt; the model reads the whole
 * article through {@see GetFaqArticleTool}.
 *
 * The package does not register it: a host adds it to the
 * {@see SupportToolRegistry} when it wants the AI to read its knowledge base.
 */
final class SearchFaqTool implements SupportTool
{
    public const QUERY_MAX_LENGTH = 200;

    public function __construct(
        private readonly SearchesSupportArticles $search,
        private readonly SupportArticleText $text,
        private readonly Application $app,
    ) {}

    public function name(): string
    {
        return 'search_faq';
    }

    public function description(): string
    {
        return 'Searches the knowledge base for articles that answer the customer\'s question and lists the best '
            . 'matches with a short excerpt. Read an article in full with get_faq_article before you rely on it.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'maxLength' => self::QUERY_MAX_LENGTH,
                    'description' => 'The question or its key words, in the customer\'s language.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => SupportArticleSearch::MAX_RESULTS,
                    'description' => 'How many articles to list, 3 when left out.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function handle(SupportToolContext $context, array $arguments): string
    {
        $query = is_string($arguments['query'] ?? null)
            ? mb_substr($arguments['query'], 0, self::QUERY_MAX_LENGTH)
            : '';
        $limit = is_int($arguments['limit'] ?? null) ? $arguments['limit'] : 3;

        $results = $this->search->search($query, $context->audience(), $this->app->getLocale(), $limit);

        if ($results === []) {
            return 'No knowledge-base article matches that question. Try other key words, or tell the customer '
                . 'a teammate will help.';
        }

        return $this->text->render(array_map(fn (array $entry): array => [
            'id' => $entry['id'],
            'slug' => $entry['slug'],
            'question' => $this->text->line($entry['question']),
            'excerpt' => $this->text->line($entry['body']),
        ], $results));
    }
}
