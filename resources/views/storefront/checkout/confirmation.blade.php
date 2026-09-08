<x-storefront-layout>
    <x-slot name="title">Order {{ $order->order_number }}</x-slot>

    <div class="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">
        <h1 class="text-2xl font-semibold">Thanks — your order is in.</h1>
        <p class="mt-2 text-slate-600">
            Order <span class="font-medium">{{ $order->order_number }}</span> ·
            {{ $order->status->label() }}
        </p>

        <table class="mt-8 w-full text-sm">
            <thead class="border-b border-slate-200 text-left text-slate-500">
                <tr>
                    <th class="py-2">Item</th>
                    <th class="py-2 text-right">Qty</th>
                    <th class="py-2 text-right">Line total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($order->items as $item)
                    <tr class="border-b border-slate-100">
                        <td class="py-2">{{ $item->product_title }}</td>
                        <td class="py-2 text-right">{{ $item->quantity }}</td>
                        <td class="py-2 text-right">${{ number_format($item->line_total, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2" class="py-3 text-right font-medium">Total</td>
                    <td class="py-3 text-right font-semibold">${{ number_format($order->total, 2) }}</td>
                </tr>
            </tfoot>
        </table>

        <a href="{{ route('products.index') }}"
           class="mt-8 inline-block rounded-md border border-slate-300 px-5 py-2.5 hover:bg-slate-50">
            Keep shopping
        </a>
    </div>
</x-storefront-layout>
