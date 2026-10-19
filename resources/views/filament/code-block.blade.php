{{--
    A value shown as code: highlighted JSON or plain text, with a language tag, a line count and a
    copy button, scrolling past its maximum height.

    Filament does not compile Tailwind for package views, so this view carries its own small
    stylesheet (prefixed `msl-code`), with a dark variant under Filament's `.dark` root. The body
    is CodeBlock::html(), which escapes every token and every gap between tokens itself.
--}}
@php
    $block = $getCodeBlock();
@endphp
@once
    <style>
        .msl-code { --msl-code-bg: #f8fafc; --msl-code-head: #f1f5f9; --msl-code-border: rgba(113, 113, 122, 0.25); --msl-code-muted: #64748b; --msl-code-key: #6d28d9; --msl-code-str: #047857; --msl-code-num: #b45309; --msl-code-lit: #be123c; border: 1px solid var(--msl-code-border); border-radius: 8px; overflow: hidden; background: var(--msl-code-bg); }
        .dark .msl-code { --msl-code-bg: #0b0f14; --msl-code-head: #11161d; --msl-code-border: rgba(161, 161, 170, 0.2); --msl-code-muted: #94a3b8; --msl-code-key: #c4b5fd; --msl-code-str: #6ee7b7; --msl-code-num: #fcd34d; --msl-code-lit: #fda4af; }
        .msl-code-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 4px 6px 4px 12px; background: var(--msl-code-head); border-bottom: 1px solid var(--msl-code-border); font-size: 12px; color: var(--msl-code-muted); }
        .msl-code-tag { font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; }
        .msl-code-copy { font: inherit; color: inherit; background: none; border: 0; border-radius: 6px; padding: 4px 8px; cursor: pointer; }
        .msl-code-copy:hover { background: var(--msl-code-border); }
        .msl-code-body { margin: 0; padding: 10px 12px; overflow: auto; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12.5px; line-height: 1.55; white-space: pre-wrap; overflow-wrap: anywhere; }
        .msl-code-key { color: var(--msl-code-key); }
        .msl-code-str { color: var(--msl-code-str); }
        .msl-code-num { color: var(--msl-code-num); }
        .msl-code-lit { color: var(--msl-code-lit); font-weight: 600; }
    </style>
@endonce
@if ($block !== null)
    <div class="msl-code" x-data="{ copied: false }">
        <div class="msl-code-head">
            <span>
                <span class="msl-code-tag">{{ __("magic-starter::admin.code.{$block->language}") }}</span>
                · {{ trans_choice('magic-starter::admin.code.lines', $block->lines(), ['count' => $block->lines()]) }}
            </span>
            <button
                type="button"
                class="msl-code-copy"
                x-on:click="window.navigator.clipboard.writeText($refs.code.innerText); copied = true; setTimeout(() => copied = false, 1500)"
            >
                <span x-show="! copied">{{ __('magic-starter::admin.code.copy') }}</span>
                <span x-show="copied" x-cloak>{{ __('magic-starter::admin.code.copied') }}</span>
            </button>
        </div>
        <pre class="msl-code-body" style="max-height: {{ $getMaxHeight() }}rem" x-ref="code">{!! $block->html() !!}</pre>
    </div>
@endif
