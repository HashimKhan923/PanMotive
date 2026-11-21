import { router, type Href } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';

import type { AppLink } from '@/api/types';

/**
 * Opens an app screen from a link the server sent (banner buttons, notifications, announcements).
 * Anything the app has no screen for opens in the in-app browser, never in a separate app.
 */
export function openLink(link: AppLink | null | undefined) {
  if (!link) return;
  switch (link.screen) {
    case 'product':
      return router.push({ pathname: '/product/[slug]', params: { slug: link.slug } });
    case 'seller':
      return router.push({ pathname: '/store/[slug]', params: { slug: link.slug } });
    case 'order':
      return router.push({ pathname: '/order/[number]', params: { number: link.id } });
    case 'conversation':
      return router.push({ pathname: '/messages/[id]', params: { id: String(link.id) } });
    case 'shop':
      return router.push({ pathname: '/shop', params: link.params });
    case 'home':
      return router.navigate('/');
    case 'deals':
      return router.navigate('/deals');
    case 'cart':
      return router.navigate('/cart');
    case 'web':
      return void WebBrowser.openBrowserAsync(link.url, { toolbarColor: '#15191E', controlsColor: '#E51B2B' });
    default:
      return router.push(('/' + link.screen) as Href);
  }
}
