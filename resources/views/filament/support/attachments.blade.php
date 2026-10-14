{{--
    The files on one message, under its bubble. An image is shown from the panel route, which
    serves it inline with a fixed type and `nosniff`. A PDF is a download only, labelled as the
    customer's untrusted file; the route answers it as an attachment, so it never renders here.

    @var list<array{name: string, url: string, image: bool}> $attachments
--}}
<div class="msl-files">
    @foreach ($attachments as $file)
        @if ($file['image'])
            <a class="msl-file-image" href="{{ $file['url'] }}" target="_blank" rel="noopener noreferrer" title="{{ $file['name'] }}"><img src="{{ $file['url'] }}" alt="{{ $file['name'] }}" loading="lazy"></a>
        @else
            <a class="msl-file-pdf" href="{{ $file['url'] }}" download rel="noopener noreferrer">{{ __('magic-starter::admin_support.attachments.untrusted_pdf') }} &middot; {{ $file['name'] }}</a>
        @endif
    @endforeach
</div>
