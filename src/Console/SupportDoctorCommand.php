<?php

namespace FlutterSdk\MagicStarter\Console;

use FlutterSdk\MagicStarter\Contracts\RespondsToSupport;
use FlutterSdk\MagicStarter\Jobs\RespondToSupportConversation;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;

/**
 * Check that something consumes the AI desk's queue before customers wait on it.
 *
 * With {@see RespondsToSupport} bound, every verified customer's conversation
 * starts `pending` and waits for {@see RespondToSupportConversation} on
 * `support.ai.queue`. If no worker reads that queue, nobody answers and every
 * turn waits out the sweeper. So the command fails when the responder is bound
 * and no Horizon supervisor, in any environment, lists the queue. A
 * supervisor's options are its environment entry over the `horizon.defaults`
 * entry of the same name, as Horizon merges them; `queue` may be a list or a
 * comma-separated string.
 *
 * With nothing bound the AI desk is off and there is nothing to check.
 */
class SupportDoctorCommand extends Command
{
    public const NAME = 'magic-starter:support:doctor';

    /**
     * @var string
     */
    protected $signature = self::NAME;

    /**
     * @var string
     */
    protected $description = 'Check that a Horizon supervisor consumes the AI support queue';

    /**
     * @return int non-zero when the responder is bound and no supervisor lists its queue
     */
    public function handle(Container $container): int
    {
        if (! $container->bound(RespondsToSupport::class)) {
            $this->components->info('The AI desk is off: RespondsToSupport is not bound. Nothing to check.');

            return self::SUCCESS;
        }

        $queue = RespondToSupportConversation::queueName();
        $supervisors = $this->supervisorsListing($queue);

        if ($supervisors === []) {
            $this->components->error(sprintf(
                'No Horizon supervisor in any environment lists the "%s" queue: pending support '
                . 'conversations would wait for the sweeper. Add it to a supervisor in config/horizon.php.',
                $queue,
            ));

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'The "%s" queue is consumed by %s.',
            $queue,
            implode(', ', $supervisors),
        ));

        return self::SUCCESS;
    }

    /**
     * The `environment.supervisor` names whose merged options list the queue.
     *
     * @return list<string>
     */
    private function supervisorsListing(string $queue): array
    {
        $defaults = (array) config('horizon.defaults', []);
        $found = [];

        foreach ((array) config('horizon.environments', []) as $environment => $supervisors) {
            foreach ((array) $supervisors as $name => $options) {
                $merged = [
                    ...(array) ($defaults[$name] ?? []),
                    ...(array) $options,
                ];

                if (in_array($queue, $this->queuesOf($merged['queue'] ?? []), true)) {
                    $found[] = $environment . '.' . $name;
                }
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function queuesOf(mixed $queue): array
    {
        $names = is_array($queue) ? $queue : explode(',', (string) $queue);

        return array_values(array_map(static fn (mixed $name): string => trim((string) $name), $names));
    }
}
