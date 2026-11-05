<?php

namespace App\Services\Tax;

use Stripe\StripeClient;

/** The only code that talks to Stripe Tax. Money amounts here are in cents, as Stripe uses them. */
class StripeTaxClient
{
    private function stripe(): StripeClient
    {
        return new StripeClient(config('services.stripe.secret') ?: 'sk_test_placeholder');
    }

    /** @return array{id:string, tax:int, shipping_tax:int, lines:array<string,int>} */
    public function calculate(array $params): array
    {
        $stripe = $this->stripe();
        $calculation = $stripe->tax->calculations->create($params);

        $lines = [];
        foreach ($stripe->tax->calculations->allLineItems($calculation->id, ['limit' => 100])->autoPagingIterator() as $line) {
            $lines[$line->reference] = (int) $line->amount_tax;
        }

        return [
            'id' => $calculation->id,
            'tax' => (int) $calculation->tax_amount_exclusive,
            'shipping_tax' => (int) ($calculation->shipping_cost->amount_tax ?? 0),
            'lines' => $lines,
        ];
    }

    /** Turn a calculation into a recorded sale, which is what Stripe's tax reports are built from. */
    public function record(string $calculationId, string $reference): string
    {
        return $this->stripe()->tax->transactions->createFromCalculation([
            'calculation' => $calculationId,
            'reference' => $reference,
        ])->id;
    }

    /** Take a refund off the recorded sale. $cents is the amount refunded, tax included. */
    public function reverse(string $transactionId, string $reference, int $cents): string
    {
        return $this->stripe()->tax->transactions->createReversal([
            'mode' => 'partial',
            'original_transaction' => $transactionId,
            'reference' => $reference,
            'flat_amount' => -$cents,
        ])->id;
    }
}
