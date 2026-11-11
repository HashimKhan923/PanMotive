@props(['stopUrl' => null, 'stopLabel' => null, 'reason' => null, 'audience' => 'buyer'])
@php
    $support = \App\Support\MailSettings::supportEmail();
    $address = \App\Support\MailSettings::postalAddress();
    $links = $audience === 'seller'
        ? ['Seller dashboard' => route('seller.dashboard'), 'Orders' => route('seller.orders.index'), 'Payouts' => route('seller.payouts.index')]
        : ['Your orders' => route('orders.index'), 'Track an order' => route('track.form'), 'Shop parts' => config('app.url')];
@endphp
<tr>
<td>
<table class="footer" align="center" width="600" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="content-cell" align="center" style="padding:24px 32px 40px;">
<p style="margin:0 0 10px;font-size:13px;line-height:20px;color:#3D444E;text-align:center;">Questions? Reply to this email or write to <a href="mailto:{{ $support }}" style="color:#15191E;font-weight:600;text-decoration:none;">{{ $support }}</a></p>
<p style="margin:0 0 14px;font-size:12px;line-height:18px;text-align:center;">{!! collect($links)->map(fn ($url, $label) => '<a href="'.e($url).'" style="color:#5E6672;text-decoration:underline;">'.e($label).'</a>')->implode(' &nbsp;·&nbsp; ') !!}</p>
@if ($reason)
<p style="margin:0 0 8px;font-size:12px;line-height:18px;color:#5E6672;text-align:center;">{{ $reason }}</p>
@endif
@if ($stopUrl)
<p style="margin:0 0 8px;font-size:12px;line-height:18px;color:#5E6672;text-align:center;"><a href="{{ $stopUrl }}" style="color:#5E6672;text-decoration:underline;">Stop {{ $stopLabel ? strtolower($stopLabel).' emails' : 'these emails' }}</a> &nbsp;·&nbsp; <a href="{{ route('marketing.preferences') }}" style="color:#5E6672;text-decoration:underline;">Email preferences</a></p>
@endif
<p style="margin:0;font-size:12px;line-height:18px;color:#8A919C;text-align:center;">© {{ date('Y') }} Panmotive · The marketplace for auto parts{{ $address ? ' · '.$address : '' }}</p>
</td>
</tr>
</table>
</td>
</tr>
