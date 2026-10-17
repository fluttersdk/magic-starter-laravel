{{--
    The public newsletter unsubscribe page. Standalone on purpose: it renders
    outside any app layout, session or asset pipeline, so everything it needs is inline.
    $state is confirm, done or not_found; $action is the store url, set only in confirm.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light dark">
    <title>{{ __('magic-starter::newsletter.page.title') }} | {{ config('app.name') }}</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            background: #f4f4f5;
            color: #18181b;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 16px;
            line-height: 1.5;
        }
        main {
            width: 100%;
            max-width: 440px;
            padding: 32px 24px;
            background: #ffffff;
            border: 1px solid #e4e4e7;
            border-radius: 12px;
        }
        .brand { margin: 0 0 24px; font-size: 14px; font-weight: 600; color: #52525b; }
        h1 { margin: 0 0 12px; font-size: 22px; line-height: 1.3; }
        p { margin: 0; color: #3f3f46; }
        form { margin: 24px 0 0; }
        button {
            width: 100%;
            min-height: 48px;
            padding: 12px 16px;
            border: 0;
            border-radius: 8px;
            background: #18181b;
            color: #ffffff;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }
        button:focus-visible { outline: 3px solid #a1a1aa; outline-offset: 2px; }
        @media (prefers-color-scheme: dark) {
            body { background: #09090b; color: #fafafa; }
            main { background: #18181b; border-color: #27272a; }
            .brand { color: #a1a1aa; }
            p { color: #d4d4d8; }
            button { background: #fafafa; color: #18181b; }
        }
    </style>
</head>
<body>
    <main>
        <p class="brand">{{ config('app.name') }}</p>
        <h1>{{ __('magic-starter::newsletter.page.' . $state . '_heading') }}</h1>
        <p>{{ __('magic-starter::newsletter.page.' . $state . '_body') }}</p>
        @if ($state === 'confirm' && $action !== null)
            <form method="POST" action="{{ $action }}">
                <button type="submit">{{ __('magic-starter::newsletter.page.confirm_button') }}</button>
            </form>
        @endif
    </main>
</body>
</html>
