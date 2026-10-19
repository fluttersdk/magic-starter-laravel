<?php

namespace FlutterSdk\MagicStarter\Support\SupportAi\Tools;

use FlutterSdk\MagicStarter\Contracts\SupportTool;
use FlutterSdk\MagicStarter\Support\SupportAi\SupportArticleSearch;
use Illuminate\Contracts\Foundation\Application;

/**
 * Lets the support AI read one knowledge-base article in full, as plain text.
 *
 * The id is looked up inside the corpus of {@see SupportToolContext::audience()},
 * never loaded and checked afterwards, and an article the audience may not read
 * answers the very sentence a missing id does: the model cannot learn that a
 * staff-only article exists by guessing ids. Images arrive as their alt text and
 * links as their label, never as a url.
 *
 * The package does not register it: a host adds it to the
 * {@see SupportToolRegistry} next to {@see SearchFaqTool}.
 */
final class GetFaqArticleTool implements SupportTool
{
    private const NOT_FOUND = 'There is no knowledge-base article with that id. Use an id search_faq returned.';

    public function __construct(
        private readonly SupportArticleSearch $search,
        private readonly SupportArticleText $text,
        private readonly Application $app,
    ) {}

    public function name(): string
    {
        return 'get_faq_article';
    }

    public function description(): string
    {
        return 'Reads one knowledge-base article in full, by the id search_faq returned. Answer from it in your '
            . 'own words.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'string',
                    'maxLength' => 64,
                    'description' => 'The article id from search_faq.',
                ],
            ],
            'required' => ['id'],
        ];
    }

    public function handle(SupportToolContext $context, array $arguments): string
    {
        $id = $arguments['id'] ?? null;

        if (! is_int($id) && ! is_string($id)) {
            return self::NOT_FOUND;
        }

        $article = $this->search->article($id, $context->audience(), $this->app->getLocale());

        if ($article === null) {
            return self::NOT_FOUND;
        }

        return $this->text->render([
            'id' => $article['id'],
            'slug' => $article['slug'],
            'question' => $this->text->line($article['question']),
            'body' => $this->text->body($article['body']),
        ]);
    }
}
