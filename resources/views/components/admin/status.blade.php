@props(['value', 'label' => null])
@php
    $raw = $value instanceof \BackedEnum ? $value->value : (string) $value;
    $tone = match ($raw) {
        'approved', 'active', 'published', 'verified', 'paid', 'delivered', 'payout_released', 'resolved', 'granted', 'completed', 'succeeded', 'yes', 'on' => 'good',
        'pending', 'pending_review', 'placed', 'requested', 'open', 'seller_responded', 'return_requested', 'scheduled', 'paused', 'draft', 'warning', 'return_approved', 'received', 'held' => 'warn',
        'suspended', 'rejected', 'banned', 'denied', 'cancelled', 'refunded', 'disputed', 'escalated', 'failed', 'critical', 'reversed', 'expired', 'off', 'removed' => 'bad',
        'shipped', 'out_for_delivery', 'seller_accepted', 'info' => 'info',
        default => 'mute',
    };
@endphp
<span {{ $attributes->class("adm-pill adm-pill-{$tone}") }}>{{ $label ?? ucfirst(str_replace('_', ' ', $raw)) }}</span>
