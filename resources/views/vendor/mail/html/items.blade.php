@props(['data' => []])
@if (! empty($data['heading']))
<p style="margin:8px 0 0;font-size:13px;line-height:18px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#5E6672;">{{ $data['heading'] }}</p>
@endif
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:8px 0 20px;border-top:1px solid #E6E9ED;">
@foreach ($data['items'] ?? [] as $item)
<tr>
<td width="68" valign="top" style="padding:14px 12px 14px 0;border-bottom:1px solid #E6E9ED;">
@if (! empty($item['image']))
<img src="{{ $item['image'] }}" alt="" width="56" height="56" style="display:block;width:56px;height:56px;border-radius:8px;border:1px solid #E6E9ED;object-fit:cover;">
@else
<table cellpadding="0" cellspacing="0" role="presentation"><tr><td width="56" height="56" align="center" style="width:56px;height:56px;border-radius:8px;background-color:#F5F6F8;border:1px solid #E6E9ED;font-size:11px;color:#8A919C;">Part</td></tr></table>
@endif
</td>
<td valign="top" style="padding:14px 0;border-bottom:1px solid #E6E9ED;">
<p style="margin:0;font-size:15px;line-height:21px;font-weight:600;color:#15191E;">@if (! empty($item['url']))<a href="{{ $item['url'] }}" style="color:#15191E;text-decoration:none;word-break:normal;">{{ $item['title'] }}</a>@else{{ $item['title'] }}@endif</p>
@if (! empty($item['meta']))
<p style="margin:3px 0 0;font-size:13px;line-height:18px;color:#5E6672;">{{ $item['meta'] }}</p>
@endif
</td>
<td valign="top" align="right" style="padding:14px 0 14px 12px;border-bottom:1px solid #E6E9ED;white-space:nowrap;">
@if (isset($item['price']))
<p style="margin:0;font-size:15px;line-height:21px;font-weight:600;color:#15191E;text-align:right;">{{ $item['price'] }}</p>
@endif
@if (! empty($item['qty']))
<p style="margin:3px 0 0;font-size:13px;line-height:18px;color:#5E6672;text-align:right;">Qty {{ $item['qty'] }}</p>
@endif
</td>
</tr>
@endforeach
</table>
