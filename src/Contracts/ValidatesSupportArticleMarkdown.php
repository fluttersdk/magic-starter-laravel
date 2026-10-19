<?php

namespace FlutterSdk\MagicStarter\Contracts;

use FlutterSdk\MagicStarter\Enums\SupportArticleVisibility;

/**
 * Contract for the host's check of an article body, on top of the package's own.
 *
 * Optional, with no default and no binding. The package already refuses image
 * syntax in a body that is not public; what else a body may carry belongs to
 * the host, which renders the Markdown and so knows which placeholders it can
 * fill and which images it can serve. With nothing bound no extra check runs.
 *
 * The panel calls it for every non-empty body when the form is validated, and
 * shows each returned message as an error on that body's field. The article is
 * not saved while any is returned.
 */
interface ValidatesSupportArticleMarkdown
{
    /**
     * The reasons the Markdown is not acceptable, or an empty list when it is.
     *
     * Messages are shown to staff as written, so they are already translated.
     *
     * @return list<string>
     */
    public function validate(string $markdown, SupportArticleVisibility $visibility, string $locale): array;
}
