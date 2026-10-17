@php($brand = \FlutterSdk\MagicStarter\Support\MailBrand::current())
{{-- Laravel's default.css with the brand's values. The inliner drops every media query from this
     stylesheet, so the responsive and dark rules live in the layout's own style tag instead. --}}
/* Base */

body,
body *:not(html):not(style):not(br):not(tr):not(code) {
    box-sizing: border-box;
    font-family: {!! $brand->fontStack !!};
    position: relative;
}

body {
    -webkit-text-size-adjust: none;
    background-color: {{ $brand->light['background'] }};
    color: {{ $brand->light['text'] }};
    height: 100%;
    line-height: 1.4;
    margin: 0;
    padding: 0;
    width: 100% !important;
}

p,
ul,
ol,
blockquote {
    line-height: 1.4;
    text-align: start;
}

a {
    color: {{ $brand->light['heading'] }};
}

a img {
    border: none;
}

/* Typography */

h1 {
    color: {{ $brand->light['heading'] }};
    font-size: 18px;
    font-weight: bold;
    margin-top: 0;
    text-align: start;
}

h2 {
    font-size: 16px;
    font-weight: bold;
    margin-top: 0;
    text-align: start;
}

h3 {
    font-size: 14px;
    font-weight: bold;
    margin-top: 0;
    text-align: left;
}

p {
    font-size: 16px;
    line-height: 1.5em;
    margin-top: 0;
    text-align: left;
}

p.sub {
    font-size: 12px;
}

img {
    max-width: 100%;
}

/* Layout */

.wrapper {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    background-color: {{ $brand->light['background'] }};
    margin: 0;
    padding: 0;
    width: 100%;
}

.content {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    margin: 0;
    padding: 0;
    width: 100%;
}

/* Header */

.header {
    padding: 25px 0;
    text-align: center;
}

.header a {
    color: {{ $brand->light['heading'] }};
    font-size: 19px;
    font-weight: bold;
    text-decoration: none;
}

.header-name {
    color: {{ $brand->light['heading'] }};
    font-size: 19px;
    font-weight: bold;
    vertical-align: middle;
}

/* Logo */

.logo {
    width: {{ $brand->logoWidth }}px;
    height: auto;
    max-width: 100%;
    vertical-align: middle;
}

/* Body */

.body {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    background-color: {{ $brand->light['background'] }};
    border-bottom: 1px solid {{ $brand->light['background'] }};
    border-top: 1px solid {{ $brand->light['background'] }};
    margin: 0;
    padding: 0;
    width: 100%;
}

.inner-body {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 570px;
    background-color: {{ $brand->light['card'] }};
    border-color: {{ $brand->light['border'] }};
    border-radius: {{ $brand->cardRadius }}px;
    border-width: 1px;
    box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.1);
    margin: 0 auto;
    padding: 0;
    width: 570px;
}

.inner-body a {
    word-break: break-all;
}

/* Subcopy */

.subcopy {
    border-top: 1px solid {{ $brand->light['border'] }};
    margin-top: 25px;
    padding-top: 25px;
}

.subcopy p {
    font-size: 14px;
}

/* Footer */

.footer {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 570px;
    margin: 0 auto;
    padding: 0;
    text-align: center;
    width: 570px;
}

.footer p {
    color: {{ $brand->light['muted'] }};
    font-size: 12px;
    text-align: center;
}

.footer a {
    color: {{ $brand->light['muted'] }};
    text-decoration: underline;
}

/* Tables */

.table table {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    margin: 30px auto;
    width: 100%;
}

.table th {
    border-bottom: 1px solid {{ $brand->light['border'] }};
    margin: 0;
    padding-bottom: 8px;
}

.table td {
    color: {{ $brand->light['text'] }};
    font-size: 15px;
    line-height: 18px;
    margin: 0;
    padding: 10px 0;
}

.content-cell {
    max-width: 100vw;
    padding: 32px;
}

/* Buttons */

.action {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    margin: 30px auto;
    padding: 0;
    text-align: center;
    width: 100%;
    float: unset;
}

.button {
    -webkit-text-size-adjust: none;
    border-radius: {{ $brand->buttonRadius }}px;
    color: {{ $brand->light['on_primary'] }};
    display: inline-block;
    overflow: hidden;
    text-decoration: none;
}

.button-blue,
.button-primary {
    background-color: {{ $brand->light['primary'] }};
    border-bottom: 8px solid {{ $brand->light['primary'] }};
    border-left: 18px solid {{ $brand->light['primary'] }};
    border-right: 18px solid {{ $brand->light['primary'] }};
    border-top: 8px solid {{ $brand->light['primary'] }};
}

.button-green,
.button-success {
    background-color: #16a34a;
    border-bottom: 8px solid #16a34a;
    border-left: 18px solid #16a34a;
    border-right: 18px solid #16a34a;
    border-top: 8px solid #16a34a;
}

.button-red,
.button-error {
    background-color: #dc2626;
    border-bottom: 8px solid #dc2626;
    border-left: 18px solid #dc2626;
    border-right: 18px solid #dc2626;
    border-top: 8px solid #dc2626;
}

/* Panels */

.panel {
    border-left: {{ $brand->light['primary'] }} solid 4px;
    margin: 21px 0;
}

.panel-content {
    background-color: {{ $brand->light['background'] }};
    color: {{ $brand->light['text'] }};
    padding: 16px;
}

.panel-content p {
    color: {{ $brand->light['text'] }};
}

.panel-item {
    padding: 0;
}

.panel-item p:last-of-type {
    margin-bottom: 0;
    padding-bottom: 0;
}

/* Utilities */

.break-all {
    word-break: break-all;
}
