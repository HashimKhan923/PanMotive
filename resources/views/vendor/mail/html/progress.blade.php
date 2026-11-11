@props(['data' => []])
@php
    $steps = $data['steps'] ?? ['Ordered', 'Shipped', 'Delivered'];
    $current = (int) ($data['current'] ?? 0);
    $width = floor(100 / max(count($steps), 1));
@endphp
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:4px 0 24px;">
<tr>
@foreach ($steps as $i => $step)
<td width="{{ $width }}%" valign="top" style="padding:0 {{ $loop->last ? '0' : '4px' }} 0 {{ $loop->first ? '0' : '4px' }};">
<div style="height:6px;border-radius:3px;background-color:{{ $i <= $current ? '#E51B2B' : '#E6E9ED' }};font-size:0;line-height:0;">&nbsp;</div>
<p style="margin:8px 0 0;font-size:12px;line-height:16px;font-weight:{{ $i === $current ? '700' : '500' }};color:{{ $i <= $current ? '#15191E' : '#8A919C' }};text-align:left;">{{ $step }}</p>
</td>
@endforeach
</tr>
</table>
