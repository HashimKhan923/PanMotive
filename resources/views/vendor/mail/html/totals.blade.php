@props(['data' => []])
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 24px;">
@foreach ($data['rows'] ?? [] as $row)
@php($strong = ! empty($row[2]))
<tr>
<td style="padding:{{ $strong ? '12px' : '4px' }} 0 4px;font-size:{{ $strong ? '16px' : '14px' }};line-height:20px;color:{{ $strong ? '#15191E' : '#3D444E' }};font-weight:{{ $strong ? '700' : '400' }};{{ $strong ? 'border-top:2px solid #15191E;' : '' }}">{{ $row[0] }}</td>
<td align="right" style="padding:{{ $strong ? '12px' : '4px' }} 0 4px;font-size:{{ $strong ? '16px' : '14px' }};line-height:20px;color:{{ ! empty($row[3]) ? '#18794E' : '#15191E' }};font-weight:{{ $strong ? '700' : '500' }};white-space:nowrap;{{ $strong ? 'border-top:2px solid #15191E;' : '' }}">{{ $row[1] }}</td>
</tr>
@endforeach
</table>
