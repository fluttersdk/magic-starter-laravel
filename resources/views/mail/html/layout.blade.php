@php($brand = \FlutterSdk\MagicStarter\Support\MailBrand::current())
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ $brand->name }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="{{ $brand->colorScheme() }}">
<meta name="supported-color-schemes" content="{{ $brand->colorScheme() }}">
<style>
@media only screen and (max-width: 600px) {
.inner-body {
width: 100% !important;
}

.footer {
width: 100% !important;
}
}

@media only screen and (max-width: 500px) {
.button {
width: 100% !important;
}
}
</style>
@if ($brand->dark !== null)
@php($dark = $brand->dark)
{{-- The inline light styles win over any stylesheet rule, so every dark rule needs !important.
     Outlook.com ignores the media query and marks the elements it recolours with data-ogsc (text)
     and data-ogsb (backgrounds), which the attribute rules target. The inliner matches none of
     these rules against the mail, so they reach the client only through this style tag, which is
     separate so a client that rejects the attribute selectors keeps the responsive rules above. --}}
<style>
@media (prefers-color-scheme: dark) {
body,
.wrapper,
.body,
.panel-content {
background-color: {{ $dark['background'] }} !important;
}

.body {
border-bottom-color: {{ $dark['background'] }} !important;
border-top-color: {{ $dark['background'] }} !important;
}

.inner-body {
background-color: {{ $dark['card'] }} !important;
border-color: {{ $dark['border'] }} !important;
}

.subcopy {
border-top-color: {{ $dark['border'] }} !important;
}

.table th {
border-bottom-color: {{ $dark['border'] }} !important;
}

p,
td,
.table td,
.panel-content {
color: {{ $dark['text'] }} !important;
}

h1,
h2,
h3,
a,
.header a,
.header-name {
color: {{ $dark['heading'] }} !important;
}

.panel {
border-left-color: {{ $dark['primary'] }} !important;
}

.footer p,
.footer a {
color: {{ $dark['muted'] }} !important;
}

.button-blue,
.button-primary {
background-color: {{ $dark['primary'] }} !important;
border-color: {{ $dark['primary'] }} !important;
color: {{ $dark['on_primary'] }} !important;
}
}

[data-ogsb] .wrapper,
[data-ogsb] .body,
[data-ogsb] .panel-content {
background-color: {{ $dark['background'] }} !important;
}

[data-ogsb] .body {
border-bottom-color: {{ $dark['background'] }} !important;
border-top-color: {{ $dark['background'] }} !important;
}

[data-ogsb] .inner-body {
background-color: {{ $dark['card'] }} !important;
border-color: {{ $dark['border'] }} !important;
}

[data-ogsb] .subcopy {
border-top-color: {{ $dark['border'] }} !important;
}

[data-ogsb] .table th {
border-bottom-color: {{ $dark['border'] }} !important;
}

[data-ogsc] p,
[data-ogsc] td,
[data-ogsc] .table td,
[data-ogsc] .panel-content {
color: {{ $dark['text'] }} !important;
}

[data-ogsc] h1,
[data-ogsc] h2,
[data-ogsc] h3,
[data-ogsc] a,
[data-ogsc] .header a,
[data-ogsc] .header-name {
color: {{ $dark['heading'] }} !important;
}

[data-ogsb] .panel {
border-left-color: {{ $dark['primary'] }} !important;
}

[data-ogsc] .footer p,
[data-ogsc] .footer a {
color: {{ $dark['muted'] }} !important;
}

[data-ogsb] .button-blue,
[data-ogsb] .button-primary {
background-color: {{ $dark['primary'] }} !important;
border-color: {{ $dark['primary'] }} !important;
}

[data-ogsc] .button-blue,
[data-ogsc] .button-primary {
color: {{ $dark['on_primary'] }} !important;
}
</style>
@endif
{!! $head ?? '' !!}
</head>
<body>

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}

<!-- Email Body -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="border: hidden !important;">
<table class="inner-body" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<!-- Body content -->
<tr>
<td class="content-cell">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
