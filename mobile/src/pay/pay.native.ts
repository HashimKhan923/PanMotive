import { initPaymentSheet, initStripe, presentPaymentSheet } from '@stripe/stripe-react-native';

export type Payment = { client_secret: string; publishable_key: string; merchant_name: string; amount: number };
export type PayResult = 'paid' | 'cancelled' | { error: string };

let initialisedWith: string | null = null;

/**
 * Takes the payment with Stripe's native payment sheet: saved cards, Apple Pay on iPhone and Google Pay
 * on Android. The order is only marked paid when Stripe tells our server (webhook); the app then waits
 * for that on the confirmation screen.
 */
export async function pay(payment: Payment, customerName?: string, _orderNumber?: string): Promise<PayResult> {
  if (initialisedWith !== payment.publishable_key) {
    await initStripe({ publishableKey: payment.publishable_key, merchantIdentifier: 'merchant.com.panmotive.app', urlScheme: 'panmotive' });
    initialisedWith = payment.publishable_key;
  }

  const init = await initPaymentSheet({
    paymentIntentClientSecret: payment.client_secret,
    merchantDisplayName: payment.merchant_name || 'Panmotive',
    returnURL: 'panmotive://stripe-redirect',
    applePay: { merchantCountryCode: 'US' },
    googlePay: { merchantCountryCode: 'US', currencyCode: 'USD', testEnv: payment.publishable_key.startsWith('pk_test') },
    defaultBillingDetails: customerName ? { name: customerName } : undefined,
    appearance: {
      colors: { primary: '#E51B2B', background: '#15191E', componentBackground: '#222830', componentBorder: '#3E4652', componentDivider: '#3E4652', primaryText: '#FFFFFF', secondaryText: '#C6CBD2', componentText: '#FFFFFF', placeholderText: '#7D8798', icon: '#C6CBD2' },
      shapes: { borderRadius: 12 },
      primaryButton: { colors: { background: '#E51B2B', text: '#FFFFFF' }, shapes: { borderRadius: 12 } },
    },
  });
  if (init.error) return { error: init.error.message };

  const result = await presentPaymentSheet();
  if (result.error) return result.error.code === 'Canceled' ? 'cancelled' : { error: result.error.message };

  return 'paid';
}
