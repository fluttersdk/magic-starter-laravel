{{--
    The unified diff of a prompt's code default against its stored body.

    Filament does not compile Tailwind for package views, so this view carries its own small
    stylesheet (prefixed `msl-diff`). Every line is escaped, and each one is a block element with
    its own `white-space: pre-wrap`, so no whitespace between the spans renders as text.

    @var string $diff The unified diff, empty when the two texts are the same.
    @var string $heading
    @var string $identical Shown instead of the diff when there is none.
--}}
<style>
    .msl-diff { --msl-diff-add: #dcfce7; --msl-diff-add-fg: #166534; --msl-diff-del: #fee2e2; --msl-diff-del-fg: #991b1b; --msl-diff-hunk: #ede9fe; --msl-diff-hunk-fg: #5b21b6; --msl-diff-muted: #71717a; display: flex; flex-direction: column; gap: 6px; }
    .dark .msl-diff { --msl-diff-add: #052e16; --msl-diff-add-fg: #86efac; --msl-diff-del: #450a0a; --msl-diff-del-fg: #fca5a5; --msl-diff-hunk: #2e1065; --msl-diff-hunk-fg: #c4b5fd; --msl-diff-muted: #a1a1aa; }
    .msl-diff-heading { font-size: 14px; font-weight: 500; }
    .msl-diff-lines { font-family: ui-monospace, monospace; font-size: 13px; border-radius: 8px; overflow-x: auto; border: 1px solid rgba(113, 113, 122, 0.3); }
    .msl-diff-line { display: block; padding: 0 10px; white-space: pre-wrap; overflow-wrap: anywhere; }
    .msl-diff-add { background: var(--msl-diff-add); color: var(--msl-diff-add-fg); }
    .msl-diff-del { background: var(--msl-diff-del); color: var(--msl-diff-del-fg); }
    .msl-diff-hunk, .msl-diff-head { background: var(--msl-diff-hunk); color: var(--msl-diff-hunk-fg); }
    .msl-diff-same { font-size: 13px; color: var(--msl-diff-muted); }
</style>
<div class="msl-diff">
    <div class="msl-diff-heading">{{ $heading }}</div>
    @if ($diff === '')
        <div class="msl-diff-same">{{ $identical }}</div>
    @else
        <div class="msl-diff-lines">
            @foreach (explode("\n", rtrim($diff, "\n")) as $line)
                <span class="msl-diff-line @if ($loop->index < 2) msl-diff-head @elseif (str_starts_with($line, '@@')) msl-diff-hunk @elseif (str_starts_with($line, '+')) msl-diff-add @elseif (str_starts_with($line, '-')) msl-diff-del @endif">{{ $line }}</span>
            @endforeach
        </div>
    @endif
</div>
