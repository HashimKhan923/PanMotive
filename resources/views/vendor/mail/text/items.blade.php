@props(['data' => []])
@if (! empty($data['heading']))
{{ strtoupper($data['heading']) }}
@endif
@foreach ($data['items'] ?? [] as $item)
- {{ $item['title'] }}@if (! empty($item['qty'])) (qty {{ $item['qty'] }})@endif @if (isset($item['price']))— {{ $item['price'] }}@endif

@if (! empty($item['meta']))
  {{ $item['meta'] }}
@endif
@endforeach
