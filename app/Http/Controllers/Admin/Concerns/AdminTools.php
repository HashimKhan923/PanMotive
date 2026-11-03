<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Models\Order;
use App\Models\User;
use App\Notifications\AccountNotice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;

trait AdminTools
{
    /** Every admin action that changes something goes in the audit log, in the same place. */
    protected function audit(string $description, ?Model $subject = null, array $properties = []): void
    {
        $log = activity('admin')->causedBy(auth()->user())->withProperties($properties);
        if ($subject) {
            $log->performedOn($subject);
        }
        $log->log($description);
    }

    /** Decisions reach the people they affect, including guests who only have an email address. */
    protected function tellBuyer(?Order $order, string $subject, string $body): void
    {
        if ($order?->buyer) {
            $order->buyer->notify(new AccountNotice($subject, $body));
        } elseif ($order?->guest_email) {
            Notification::route('mail', $order->guest_email)->notify(new AccountNotice($subject, $body));
        }
    }

    protected function tell(?User $user, string $subject, string $body, ?string $url = null): void
    {
        $user?->notify(new AccountNotice($subject, $body, $url));
    }

    /** LIKE treats % and _ as wildcards; a search should mean the literal text. */
    protected function like(?string $term): string
    {
        return '%'.addcslashes(trim((string) $term), '%_\\').'%';
    }

    /** Spreadsheets run cells starting with = + - @ as formulas, and sellers choose their own names. */
    protected function csvCell(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
