<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Placed = 'placed';
    case Paid = 'paid';
    case SellerAccepted = 'seller_accepted';
    case Shipped = 'shipped';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case PayoutReleased = 'payout_released';
    case ReturnRequested = 'return_requested';
    case ReturnApproved = 'return_approved';
    case Refunded = 'refunded';
    case Disputed = 'disputed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Placed => 'Placed',
            self::Paid => 'Paid',
            self::SellerAccepted => 'Accepted by Seller',
            self::Shipped => 'Shipped',
            self::OutForDelivery => 'Out for Delivery',
            self::Delivered => 'Delivered',
            self::PayoutReleased => 'Payout Released',
            self::ReturnRequested => 'Return Requested',
            self::ReturnApproved => 'Return Approved',
            self::Refunded => 'Refunded',
            self::Disputed => 'Disputed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Paid and waiting on the seller to accept. Placed means payment hasn't
     * cleared yet, so it must never be actionable by the seller.
     */
    public static function awaitingAcceptance(): array
    {
        return [self::Paid];
    }

    /** Before anything has shipped — the only window in which a seller may cancel a line. */
    public static function cancellable(): array
    {
        return [self::Placed, self::Paid, self::SellerAccepted];
    }

    /** Statuses a buyer's "confirm delivery" action or auto-confirm job can transition FROM */
    public static function deliverable(): array
    {
        return [self::Shipped, self::OutForDelivery];
    }
}
