@props(['stopUrl' => null, 'stopLabel' => null, 'reason' => null, 'audience' => 'buyer'])
@php($address = \App\Support\MailSettings::postalAddress())
--
Questions? Reply to this email or write to {{ \App\Support\MailSettings::supportEmail() }}
@if ($audience === 'seller')
Seller dashboard: {{ route('seller.dashboard') }}
@else
Your orders: {{ route('orders.index') }}
@endif
@if ($reason)
{{ $reason }}
@endif
@if ($stopUrl)
Stop {{ $stopLabel ? strtolower($stopLabel).' emails' : 'these emails' }}: {{ $stopUrl }}
@endif
© {{ date('Y') }} Panmotive · The marketplace for auto parts{{ $address ? ' · '.$address : '' }}
