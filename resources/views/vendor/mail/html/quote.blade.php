@props(['data' => []])
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:4px 0 24px;">
<tr>
<td style="padding:14px 18px;border-left:4px solid #E51B2B;background-color:#F5F6F8;border-radius:0 10px 10px 0;">
@if (! empty($data['by']))
<p style="margin:0 0 6px;font-size:13px;line-height:18px;font-weight:700;color:#5E6672;">{{ $data['by'] }}</p>
@endif
<p style="margin:0;font-size:15px;line-height:23px;color:#15191E;">{!! nl2br(e(trim((string) ($data['text'] ?? '')))) !!}</p>
</td>
</tr>
</table>
