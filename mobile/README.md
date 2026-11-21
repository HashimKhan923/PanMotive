# Panmotive shopping app

The iPhone / Android app for panmotive.com shoppers (the shop only — not the seller or admin portals).
Built with Expo (React Native). It talks to the Laravel API at `/api/shop/v1` (see `routes/api.php`).

## Run it

```bash
npm install
npx expo start          # scan the QR code with a development build on your phone
npm run typecheck
```

Point it at another server with `EXPO_PUBLIC_API_URL=http://192.168.x.x:8000/api/shop/v1 npx expo start`.

## Where things are

- `src/app` — screens (Expo Router: the file path is the screen's address)
- `src/theme` — brand colours (dark by default), type, spacing, motion springs
- `src/motion` — signature animations: forward cut, splash, card → product morph, add-to-cart flight, confetti
- `src/ui` — buttons, icons (same set as the website), logo, product card, sheets, toasts
- `src/api` — API client and types; `src/state` — session, garage, cart, preferences
- `src/pay` — Stripe payment sheet (Apple Pay / Google Pay / card)
- `src/notify` — push notifications
