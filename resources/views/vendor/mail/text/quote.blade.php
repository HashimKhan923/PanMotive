@props(['data' => []])
@if (! empty($data['by']))
{{ $data['by'] }}:
@endif
"{{ $data['text'] ?? '' }}"
