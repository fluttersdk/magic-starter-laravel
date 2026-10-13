{{--
    The staff thread: chat bubbles, an optional AI summary card, centred event lines.

    Filament does not compile Tailwind for package views, so this view carries its own
    small stylesheet (prefixed `msl-`) and reads nothing but the two arrays it is given.
    A body is escaped and set on one line with no whitespace around the echo: the
    element uses `white-space: pre-line`, so any indentation would render as text.

    @var list<array{variant: string, out: bool, body: string, avatar: string, chip: ?string, meta: list<string>}> $items
    @var array{text: string, age: string}|null $summary
--}}
<style>
    .msl-thread { --msl-in: #f4f4f5; --msl-in-fg: inherit; --msl-ai-edge: #7c3aed; --msl-muted: #71717a; --msl-rule: #e4e4e7; --msl-staff: #15803d; --msl-note: #fef3c7; --msl-note-edge: #d97706; --msl-note-fg: #92400e; --msl-wash: #f5f3ff; --msl-wash-edge: #ddd6fe; --msl-wash-fg: #5b21b6; --msl-chip: #ede9fe; --msl-avatar: #e4e4e7; --msl-avatar-staff: #dcfce7; --msl-avatar-staff-fg: #166534; --msl-avatar-ai: #ede9fe; --msl-avatar-ai-fg: #5b21b6; display: flex; flex-direction: column; gap: 14px; }
    .dark .msl-thread { --msl-in: #1c1c20; --msl-muted: #a1a1aa; --msl-rule: #26262b; --msl-note: #2a2006; --msl-note-edge: #a16207; --msl-note-fg: #fde68a; --msl-wash: #1e1145; --msl-wash-edge: #2b195a; --msl-wash-fg: #c4b5fd; --msl-chip: #3b2a78; --msl-avatar: #26262b; --msl-avatar-staff: #064e3b; --msl-avatar-staff-fg: #6ee7b7; --msl-avatar-ai: #3b2a78; --msl-avatar-ai-fg: #c4b5fd; }
    .msl-summary { display: flex; flex-direction: column; gap: 6px; padding: 14px 16px; border-radius: 10px; background: var(--msl-wash); border: 1px solid var(--msl-wash-edge); }
    .msl-summary-head { color: var(--msl-wash-fg); font-weight: 600; font-size: 13px; }
    .msl-summary-text { font-size: 14px; white-space: pre-line; overflow-wrap: anywhere; }
    .msl-row { display: flex; gap: 10px; align-items: flex-start; }
    .msl-row.msl-out { justify-content: flex-end; }
    .msl-stack { display: flex; flex-direction: column; gap: 5px; max-width: 70%; min-width: 0; }
    .msl-out .msl-stack { align-items: flex-end; }
    .msl-bubble { padding: 9px 13px; border-radius: 14px; font-size: 14px; background: var(--msl-in); color: var(--msl-in-fg); max-width: 100%; }
    .msl-body { white-space: pre-line; overflow-wrap: anywhere; }
    .msl-customer .msl-bubble { border-bottom-left-radius: 4px; }
    .msl-staff .msl-bubble { border-bottom-right-radius: 4px; background: var(--msl-staff); color: #fff; }
    .msl-ai .msl-bubble { border-bottom-right-radius: 4px; box-shadow: inset -2px 0 0 var(--msl-ai-edge); }
    .msl-note .msl-bubble { background: var(--msl-note); border: 1px dashed var(--msl-note-edge); color: var(--msl-note-fg); border-radius: 10px; }
    .msl-meta { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; font-size: 12px; color: var(--msl-muted); }
    .msl-chip { padding: 1px 7px; border-radius: 6px; background: var(--msl-chip); color: var(--msl-wash-fg); font-weight: 600; font-size: 11px; }
    .msl-customer .msl-chip { background: var(--msl-avatar); color: var(--msl-muted); }
    .msl-avatar { flex: none; width: 32px; height: 32px; border-radius: 9999px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; background: var(--msl-avatar); }
    .msl-staff .msl-avatar, .msl-note .msl-avatar { background: var(--msl-avatar-staff); color: var(--msl-avatar-staff-fg); }
    .msl-ai .msl-avatar { background: var(--msl-avatar-ai); color: var(--msl-avatar-ai-fg); }
    .msl-event { display: flex; align-items: center; gap: 10px; font-size: 12px; color: var(--msl-muted); }
    .msl-event::before, .msl-event::after { content: ""; flex: 1; height: 1px; background: var(--msl-rule); }
    @media (max-width: 1023px) { .msl-stack { max-width: 85%; } }
</style>
<div class="msl-thread">
    @if ($summary !== null)
        <div class="msl-summary">
            <div class="msl-summary-head">&#10022; {{ __('magic-starter::admin_support.ai.summary') }} &middot; {{ $summary['age'] }}</div>
            <div class="msl-summary-text">{{ $summary['text'] }}</div>
        </div>
    @endif
    @foreach ($items as $item)
        @if ($item['variant'] === 'event')
            <div class="msl-event">{{ $item['body'] }} &middot; {{ implode(' · ', $item['meta']) }}</div>
        @else
            <div class="msl-row msl-{{ $item['variant'] }} {{ $item['out'] ? 'msl-out' : '' }}">
                @unless ($item['out'])
                    <div class="msl-avatar">{{ $item['avatar'] }}</div>
                @endunless
                <div class="msl-stack">
                    <div class="msl-bubble"><div class="msl-body">{{ $item['body'] }}</div></div>
                    <div class="msl-meta">
                        @if ($item['chip'] !== null)
                            <span class="msl-chip">@if ($item['variant'] === 'ai')&#10022; @endif{{ $item['chip'] }}</span>
                        @endif
                        <span>{{ implode(' · ', $item['meta']) }}</span>
                    </div>
                </div>
                @if ($item['out'])
                    <div class="msl-avatar">{{ $item['avatar'] }}</div>
                @endif
            </div>
        @endif
    @endforeach
</div>
