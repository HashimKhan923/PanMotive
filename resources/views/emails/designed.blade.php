<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{{ $store }}</title>
<style>
@media only screen and (max-width: 620px) {
  .pm-card { width: 100% !important; border-radius: 0 !important; }
  .pm-pad { padding: 24px 18px !important; }
  .pm-col { display: block !important; width: 100% !important; padding-left: 0 !important; padding-right: 0 !important; }
}
</style>
</head>
<body style="margin:0;padding:0;background:#F5F6F8;">
@if ($preview !== '')
<div style="display:none;max-height:0;max-width:0;overflow:hidden;opacity:0;font-size:1px;line-height:1px;color:#F5F6F8;mso-hide:all;">{{ $preview }}&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;</div>
@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F5F6F8;">
<tr><td align="center" style="padding:24px 12px;">
  <table role="presentation" class="pm-card" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:600px;background:#FFFFFF;border-radius:14px;overflow:hidden;border:1px solid #E6E9ED;">
    {{-- store header --}}
    <tr><td align="center" style="padding:22px 32px;background:{{ $brand['header'] === 'dark' ? '#15191E' : '#FFFFFF' }};border-bottom:3px solid {{ $brand['color'] }};">
      <a href="{{ $storeUrl }}" style="text-decoration:none;">
        @if ($logo)
          <img src="{{ $logo }}" alt="{{ $store }}" height="44" style="display:inline-block;height:44px;width:auto;border:0;vertical-align:middle;">
        @else
          <span style="font:800 22px/1.2 Arial,Helvetica,sans-serif;color:{{ $brand['header'] === 'dark' ? '#FFFFFF' : '#15191E' }};letter-spacing:.3px;">{{ $store }}</span>
        @endif
      </a>
    </td></tr>
    {{-- blocks --}}
    <tr><td class="pm-pad" style="padding:32px;">
      @foreach ($blocks as $block)
        <div style="margin:0 0 {{ $loop->last ? '0' : '22px' }};">{!! $block !!}</div>
      @endforeach
    </td></tr>
  </table>
  {{-- footer: always added, sellers can't remove it (US CAN-SPAM rules) --}}
  <table role="presentation" width="600" cellpadding="0" cellspacing="0" class="pm-card" style="width:600px;max-width:600px;">
    <tr><td align="center" style="padding:20px 24px 8px;font:400 12px/1.6 Arial,Helvetica,sans-serif;color:#5E6672;">
      You’re getting this email because you bought from or follow <strong>{{ $store }}</strong> on Panmotive.<br>
      <a href="{{ $unsubscribeUrl }}" style="color:#5E6672;text-decoration:underline;">Unsubscribe from {{ $store }}</a>
      &nbsp;·&nbsp; <a href="{{ $preferencesUrl }}" style="color:#5E6672;text-decoration:underline;">Email preferences</a>
    </td></tr>
    <tr><td align="center" style="padding:4px 24px 24px;font:400 11px/1.6 Arial,Helvetica,sans-serif;color:#8A919C;">
      Sent by Panmotive on behalf of {{ $store }} · {{ $address }}
    </td></tr>
  </table>
  @if ($openPixel)
    <img src="{{ $openPixel }}" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;">
  @endif
</td></tr>
</table>
</body>
</html>
