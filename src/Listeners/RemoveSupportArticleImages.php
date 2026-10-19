<?php

namespace FlutterSdk\MagicStarter\Listeners;

use FlutterSdk\MagicStarter\Enums\SupportArticleVisibility;
use FlutterSdk\MagicStarter\Models\SupportArticle;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;

/**
 * Deletes the images an article carried when it is deleted or leaves `public`.
 *
 * Filament stores an attachment the moment it is uploaded, on a web-served disk, so a
 * file outlives the article unless this removes it. Paths are read from the article
 * while the event fires, because the previous body of a visibility change is gone once
 * the save finishes, and deleted after the surrounding transaction commits, so a
 * rollback never costs an article its images. A file another article still references,
 * in any locale, is kept: an image can be pasted into a second article.
 *
 * A reference reaches the disk only as a normalised path under {@see self::DIRECTORY};
 * anything else a body names (a foreign url, `..`, a leading slash) is skipped, so the
 * text staff write can never point a delete at another file. Only Markdown image
 * syntax is read.
 */
class RemoveSupportArticleImages
{
    /**
     * The directory the editor stores uploads in, and the only one this ever deletes from.
     */
    public const DIRECTORY = 'support-articles';

    /**
     * The destination of `![alt](destination "title")`, without a title, query or fragment.
     */
    private const IMAGE = '/!\[[^\]]*\]\(\s*<?([^)\s>?#]+)/';

    /**
     * The destination of a reference definition, `[ref]: destination "title"`, which a reference-style image
     * (`![alt][ref]`) resolves to; the host renderer accepts that form, so its file has a reader too.
     */
    private const REFERENCE = '/^ {0,3}\[[^\]]+\]:\s*<?([^\s>?#]+)/m';

    public function __construct(private readonly Factory $storage) {}

    /**
     * An article was removed: its images lose this reader.
     */
    public function deleted(SupportArticle $article): void
    {
        $this->removeAfterCommit($article, array_values((array) $article->body));
    }

    /**
     * An article may have left `public`, the only visibility that may carry an image.
     *
     * The bodies before and after the save are both read: the editor refuses image syntax
     * in a non-public body, so the same save that hides an article also removes its images
     * from the text.
     */
    public function updated(SupportArticle $article): void
    {
        if (
            ! $article->wasChanged('visibility')
            || $article->getOriginal('visibility') !== SupportArticleVisibility::PUBLIC
        ) {
            return;
        }

        $this->removeAfterCommit($article, [
            ...array_values((array) $article->getOriginal('body')),
            ...array_values((array) $article->body),
        ]);
    }

    /**
     * The articles disk, from `magic-starter.support.articles.disk`.
     */
    public function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = $this->storage->disk((string) config('magic-starter.support.articles.disk'));

        return $disk;
    }

    /**
     * The disk-relative path of every image the Markdown texts reference, once each.
     *
     * A url the disk itself serves is mapped back to its path. A destination that does not
     * resolve to a file under {@see self::DIRECTORY} is dropped.
     *
     * @param  iterable<mixed>  $markdowns
     * @return list<string>
     */
    public function pathsIn(iterable $markdowns): array
    {
        $prefix = $this->disk()->url('');
        $paths = [];

        foreach ($markdowns as $markdown) {
            preg_match_all(self::IMAGE, (string) $markdown, $inline);
            preg_match_all(self::REFERENCE, (string) $markdown, $references);

            foreach ([...$inline[1], ...$references[1]] as $destination) {
                $path = $this->diskPath($destination, $prefix);

                if ($path !== null) {
                    $paths[$path] = $path;
                }
            }
        }

        return array_values($paths);
    }

    /**
     * The Markdown with every image url the disk serves for a file under {@see self::DIRECTORY}
     * rewritten to its disk-relative path.
     *
     * The editor inserts an upload as an absolute url of the disk, which names the host the
     * panel ran on when it was uploaded; stored that way, a change of `APP_URL` or of the
     * disk's url would leave every reader unable to map the image back to its file. Any other
     * destination, a relative path included, is kept exactly as written.
     */
    public function relativeImages(string $markdown): string
    {
        $prefix = $this->disk()->url('');
        $relativise = function (array $match) use ($prefix): string {
            $destination = $match[1];
            $path = str_starts_with($destination, $prefix) ? $this->diskPath($destination, $prefix) : null;

            return $path === null
                ? $match[0]
                : substr($match[0], 0, -strlen($destination)) . $path;
        };

        return (string) preg_replace_callback(
            self::REFERENCE,
            $relativise,
            (string) preg_replace_callback(self::IMAGE, $relativise, $markdown),
        );
    }

    /**
     * Every path under {@see self::DIRECTORY} some article references, in any locale and of any visibility.
     *
     * @return array<string, true>
     */
    public function referencedPaths(?SupportArticle $except = null): array
    {
        $query = SupportArticle::query();

        if ($except !== null) {
            $query->whereKeyNot($except->getKey());
        }

        $referenced = [];

        foreach ($query->cursor() as $article) {
            foreach ($this->pathsIn((array) $article->body) as $path) {
                $referenced[$path] = true;
            }
        }

        return $referenced;
    }

    /**
     * Delete the article's files once the write commits, except the ones another article still shows.
     *
     * The other articles are read after the commit, so a body saved in the same transaction counts.
     *
     * @param  list<string>  $markdowns
     */
    private function removeAfterCommit(SupportArticle $article, array $markdowns): void
    {
        $paths = $this->pathsIn($markdowns);

        if ($paths === []) {
            return;
        }

        DB::afterCommit(function () use ($article, $paths): void {
            $orphans = array_keys(array_diff_key(
                array_flip($paths),
                $this->referencedPaths(except: $article),
            ));

            if ($orphans !== []) {
                $this->disk()->delete(array_map('strval', $orphans));
            }
        });
    }

    /**
     * Normalise one image destination to a path under the directory, or null when it is not one.
     */
    private function diskPath(string $destination, string $prefix): ?string
    {
        $path = rawurldecode(str_starts_with($destination, $prefix)
            ? substr($destination, strlen($prefix))
            : $destination);

        if (str_starts_with($path, '/')) {
            return null;
        }

        $segments = array_values(array_filter(
            explode('/', $path),
            static fn (string $segment): bool => $segment !== '' && $segment !== '.',
        ));

        if (
            count($segments) < 2
            || $segments[0] !== self::DIRECTORY
            || in_array('..', $segments, true)
        ) {
            return null;
        }

        return implode('/', $segments);
    }
}
