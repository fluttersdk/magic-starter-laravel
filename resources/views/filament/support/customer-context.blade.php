{{--
    The customer context beside the thread. Package views get no compiled Tailwind, so the
    view carries its own small stylesheet (prefixed `msl-ctx-`). Every value is escaped.

    @var array<string, string> $client
    @var array<string, string>|null $customer
    @var list<array{event: string, subject: string, time: string, keys: list<string>}>|null $activity
--}}
<style>
    .msl-ctx { --msl-ctx-muted: #71717a; --msl-ctx-rule: #e4e4e7; --msl-ctx-chip: #f4f4f5; display: flex; flex-direction: column; gap: 16px; font-size: 13px; }
    .dark .msl-ctx { --msl-ctx-muted: #a1a1aa; --msl-ctx-rule: #26262b; --msl-ctx-chip: #26262b; }
    .msl-ctx-head { font-weight: 600; margin-bottom: 6px; }
    .msl-ctx-pairs { display: grid; grid-template-columns: max-content 1fr; gap: 4px 12px; margin: 0; }
    .msl-ctx-pairs dt { color: var(--msl-ctx-muted); }
    .msl-ctx-pairs dd { margin: 0; overflow-wrap: anywhere; }
    .msl-ctx-rows { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; }
    .msl-ctx-rows li { padding: 6px 0; border-top: 1px solid var(--msl-ctx-rule); display: flex; flex-direction: column; gap: 2px; }
    .msl-ctx-event { font-weight: 600; overflow-wrap: anywhere; }
    .msl-ctx-meta { color: var(--msl-ctx-muted); font-size: 12px; }
    .msl-ctx-keys { display: flex; flex-wrap: wrap; gap: 4px; }
    .msl-ctx-keys span { padding: 0 6px; border-radius: 6px; background: var(--msl-ctx-chip); font-size: 11px; }
    .msl-ctx-empty { color: var(--msl-ctx-muted); }
</style>
<div class="msl-ctx">
    @if ($client !== [])
        <section>
            <div class="msl-ctx-head">{{ __('magic-starter::admin_support.context.client') }}</div>
            <dl class="msl-ctx-pairs">
                @foreach ($client as $label => $value)
                    <dt>{{ $label }}</dt>
                    <dd>{{ $value }}</dd>
                @endforeach
            </dl>
        </section>
    @endif
    @if ($customer !== null)
        <section>
            <div class="msl-ctx-head">{{ __('magic-starter::admin_support.context.customer') }}</div>
            <dl class="msl-ctx-pairs">
                @foreach ($customer as $label => $value)
                    <dt>{{ $label }}</dt>
                    <dd>{{ $value }}</dd>
                @endforeach
            </dl>
        </section>
    @endif
    @if ($activity !== null)
        <section>
            <div class="msl-ctx-head">{{ __('magic-starter::admin_support.context.activity') }}</div>
            @if ($activity === [])
                <div class="msl-ctx-empty">{{ __('magic-starter::admin_support.context.no_activity') }}</div>
            @else
                <ul class="msl-ctx-rows">
                    @foreach ($activity as $row)
                        <li>
                            <span class="msl-ctx-event">{{ $row['event'] }}</span>
                            <span class="msl-ctx-meta">{{ $row['subject'] }} &middot; {{ $row['time'] }}</span>
                            @if ($row['keys'] !== [])
                                <span class="msl-ctx-keys">
                                    @foreach ($row['keys'] as $key)
                                        <span>{{ $key }}</span>
                                    @endforeach
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif
</div>
