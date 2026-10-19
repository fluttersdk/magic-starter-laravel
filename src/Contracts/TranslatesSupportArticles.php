<?php

namespace FlutterSdk\MagicStarter\Contracts;

use FlutterSdk\MagicStarter\Models\SupportArticle;
use Throwable;

/**
 * Contract for the host's translator of knowledge-base articles.
 *
 * DELIBERATELY SHIPPED WITH NO DEFAULT IMPLEMENTATION AND NO BINDING, like
 * {@see RespondsToSupport}. Translating needs a model and a provider budget, and
 * both belong to the host: the package calls no AI SDK. With nothing bound a
 * locale staff left empty simply stays empty.
 *
 * The panel calls this when a saved article has no text in a locale, after it
 * has marked that locale `draft`. The implementation queues the work and returns;
 * it translates from the default locale (`magic-starter.defaults.locale`) and
 * writes the question and body of the locale it was asked for. It never writes
 * `approved` into `locale_state`: a translated draft reaches a reader only after
 * staff press "Approve", and a worker that approves its own output would skip
 * that review.
 *
 * Failure mode: the locale stays an empty draft, which no reader sees. Staff can
 * fill it in by hand, since the form still shows it.
 */
interface TranslatesSupportArticles
{
    /**
     * Queue a draft of the article in [$locale].
     *
     * Called once per locale per article, after the article is committed, so a
     * queued job finds the row. Saving the article again does not call it again
     * while the locale is still a draft.
     *
     * @throws Throwable when the work cannot be queued; the panel shows the save as failed
     */
    public function queueDraft(SupportArticle $article, string $locale): void;
}
