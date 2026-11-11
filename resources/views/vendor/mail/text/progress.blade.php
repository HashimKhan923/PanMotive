@props(['data' => []])
@php($steps = $data['steps'] ?? ['Ordered', 'Shipped', 'Delivered'])
{{ collect($steps)->map(fn ($s, $i) => $i === (int) ($data['current'] ?? 0) ? '['.$s.']' : $s)->implode(' > ') }}
