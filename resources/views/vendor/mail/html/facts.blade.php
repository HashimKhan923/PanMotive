@props(['data' => []])
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:4px 0 24px;background-color:#F5F6F8;border-radius:10px;">
<tr>
<td style="padding:16px 18px;">
@if (! empty($data['title']))
<p style="margin:0 0 8px;font-size:13px;line-height:18px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#5E6672;">{{ $data['title'] }}</p>
@endif
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
@foreach ($data['rows'] ?? [] as $label => $value)
<tr>
@if (is_int($label))
<td colspan="2" style="padding:3px 0;font-size:14px;line-height:20px;color:#15191E;">{{ $value }}</td>
@else
<td valign="top" style="padding:3px 12px 3px 0;font-size:14px;line-height:20px;color:#5E6672;white-space:nowrap;">{{ $label }}</td>
<td valign="top" align="right" style="padding:3px 0;font-size:14px;line-height:20px;color:#15191E;font-weight:600;text-align:right;">{{ $value }}</td>
@endif
</tr>
@endforeach
</table>
</td>
</tr>
</table>
