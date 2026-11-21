import * as WebBrowser from 'expo-web-browser';

export type Payment = { client_secret: string; publishable_key: string; merchant_name: string; amount: number };
export type PayResult = 'paid' | 'cancelled' | { error: string };

/**
 * Web preview only (the phone apps use pay.native.ts with Stripe's native sheet). There's no native
 * payment sheet in a browser, so the order is finished on the website.
 */
export async function pay(_payment: Payment, _customerName?: string, orderNumber?: string): Promise<PayResult> {
  if (orderNumber) {
    await WebBrowser.openBrowserAsync(`https://panmotive.com/orders/${orderNumber}/pay`);
    return 'cancelled';
  }
  return { error: 'Payments run in the Panmotive app on your phone.' };
}
