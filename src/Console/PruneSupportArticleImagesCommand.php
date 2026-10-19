<?php

namespace FlutterSdk\MagicStarter\Console;

use FlutterSdk\MagicStarter\Listeners\RemoveSupportArticleImages;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Deletes the uploads under `support-articles/` that no article references any more.
 *
 * The editor stores an image the moment it is uploaded, so an upload that was removed
 * from the text or never saved leaves a public file behind. A file is deleted when no
 * body of any article, in any locale and of any visibility, references it and it was last
 * written more than {@see self::GRACE_HOURS} hours ago: the grace keeps the image of an
 * article that is still being written. Nothing outside the directory on the articles disk
 * is listed, let alone deleted.
 *
 * Scheduled daily by the provider while support is on.
 */
class PruneSupportArticleImagesCommand extends Command
{
    public const NAME = 'magic-starter:support-articles:prune-images';

    public const GRACE_HOURS = 24;

    /**
     * @var string
     */
    protected $signature = self::NAME;

    /**
     * @var string
     */
    protected $description = 'Delete uploaded knowledge-base images that no article references';

    /**
     * Delete the orphaned files and print how many.
     */
    public function handle(RemoveSupportArticleImages $images): int
    {
        // 1. Every reference of every article, so a file any locale still shows is kept.
        $referenced = $images->referencedPaths();

        // 2. Only the directory the editor writes to is listed, and only files past the grace are candidates.
        $disk = $images->disk();
        $cutoff = now()->subHours(self::GRACE_HOURS)->getTimestamp();
        $deleted = 0;

        foreach ($disk->allFiles(RemoveSupportArticleImages::DIRECTORY) as $path) {
            if (isset($referenced[$path]) || $disk->lastModified($path) > $cutoff) {
                continue;
            }

            $disk->delete($path);
            $deleted++;
        }

        $this->components->info(sprintf(
            'Deleted %d orphaned support article %s.',
            $deleted,
            Str::plural('image', $deleted),
        ));

        return self::SUCCESS;
    }
}
