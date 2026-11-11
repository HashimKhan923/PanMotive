@props(['data' => []])
@foreach ($data['rows'] ?? [] as $row)
{{ $row[0] }}: {{ $row[1] }}
@endforeach
