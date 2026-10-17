@php($brand = \FlutterSdk\MagicStarter\Support\MailBrand::current())
@props(['url'])
{{-- The name comes from the brand, so the slot the message passes in is ignored. --}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if ($brand->logoUrl !== null)
<img src="{{ $brand->logoUrl }}" width="{{ $brand->logoWidth }}" class="logo" alt="{{ $brand->name }}">
@endif
<span class="header-name">{{ $brand->name }}</span>
</a>
</td>
</tr>
