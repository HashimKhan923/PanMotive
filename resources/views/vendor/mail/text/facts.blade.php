@props(['data' => []])
@if (! empty($data['title']))
{{ strtoupper($data['title']) }}
@endif
@foreach ($data['rows'] ?? [] as $label => $value)
{{ is_int($label) ? $value : $label.': '.$value }}
@endforeach
