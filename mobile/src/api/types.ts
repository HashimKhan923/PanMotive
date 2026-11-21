/** Shapes returned by the Panmotive shop API (/api/shop/v1). Mirrors app/Http/Controllers/Api/Shop/*. */

export type AppLink =
  | { screen: 'home' | 'deals' | 'plus' | 'find-parts' | 'fit-guarantee' | 'orders' | 'cart' | 'wishlist' | 'wallet' | 'messages' | 'subscriptions' | 'track' }
  | { screen: 'product'; slug: string }
  | { screen: 'seller'; slug: string }
  | { screen: 'order'; id: string }
  | { screen: 'conversation'; id: number }
  | { screen: 'shop'; params: Record<string, string> }
  | { screen: 'web'; url: string };

export type User = {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  email_verified: boolean;
  linked: ('google' | 'apple')[];
  garage: { id: number; label: string } | null;
  plus: boolean;
  is_seller: boolean;
  created_at: string;
};

export type Vehicle = {
  id: number;
  label: string;
  year: number;
  make: { id: number; name: string; slug: string } | null;
  model: { id: number; name: string; slug: string } | null;
  engine: { id: number; name: string } | null;
  trim: string | null;
};

export type ProductCard = {
  id: number;
  slug: string;
  title: string;
  image: string | null;
  image_large: string | null;
  image_alt: string | null;
  price: number;
  sale_price: number | null;
  effective_price: number;
  off_pct: number;
  deal_ends_at: string | null;
  basis: 'each' | 'pair' | 'set';
  rating: number;
  rating_count: number | null;
  rating_is_seller: boolean;
  stock: number;
  in_stock: boolean;
  has_variants: boolean;
  quick_add: boolean;
  condition: string;
  part_number: string | null;
  fits_vehicle: string | null;
  fits_garage: boolean | null;
  saved: boolean;
  seller: { name: string; slug: string } | null;
};

export type Seller = {
  name: string;
  slug: string;
  logo: string | null;
  rating: number;
  rating_count: number;
  tier: string | null;
  reply_time: string | null;
  away: boolean;
};

export type Variant = {
  id: number;
  label: string;
  options: Record<string, string>;
  sku: string | null;
  price: number;
  sale_price: number | null;
  effective_price: number;
  stock: number;
  in_stock: boolean;
  image: string | null;
  media_id: number | null;
};

export type ProductDetail = ProductCard & {
  description_html: string | null;
  oem_number: string | null;
  category: { name: string; slug: string; parent: { name: string; slug: string } | null } | null;
  gallery: { id: number; thumb: string; large: string }[];
  price_range: { min: number; max: number } | null;
  variants: Variant[];
  deal: { name: string; ends_at: string } | null;
  shipping: { handling_days: number; free: boolean; excluded_states: string[]; eta_from: string; eta_to: string };
  returns: { window_days: number; shipping: 'buyer' | 'seller' };
  vacation: { message: string; until: string | null; paused: boolean } | null;
  accepts_offers: boolean;
  subscription: { pct: number; intervals: { days: number; label: string }[] } | null;
  volume_discounts: { min_quantity: number; type: 'percentage' | 'fixed'; value: number }[];
  attributes: { name: string | null; value: string }[];
  fitments: { vehicle_id: number; year: number; make: string | null; model: string | null; engine: string | null; trim: string | null; position: string | null; notes: string | null }[];
  bundles: { id: number; title: string; price: number; full_price: number; savings: number; products: ProductCard[] }[];
  seller: (Seller & { id: number }) | null;
  reviews: { average: number; count: number; breakdown: Record<string, number> };
  questions_count: number;
  share_url: string;
};

export type Category = { id: number; name: string; slug: string; icon: string; count: number | null; children: { id: number; name: string; slug: string; count?: number }[] };

export type Banner = {
  id: number;
  placement: 'below_categories' | 'below_deals' | 'below_listings';
  style: 'charcoal' | 'red' | 'light';
  eyebrow: string | null;
  title: string;
  subtitle: string | null;
  image: string | null;
  button: { label: string; link: AppLink } | null;
  second: { label: string; link: AppLink } | null;
};

export type Home = {
  hero: { badge: string; line_1: string; line_2: string; accent: string; text: string; points: string[] };
  announcement: { text: string; tone: string; link: AppLink | null } | null;
  garage: { id: number; label: string } | null;
  categories: Category[];
  banners: Partial<Record<Banner['placement'], Banner[]>>;
  deals: ProductCard[];
  for_vehicle: ProductCard[];
  from_followed: ProductCard[];
  new: ProductCard[];
};

export type PageMeta = { page: number; last_page: number; per_page: number; total: number };

export type ProductList = {
  products: ProductCard[];
  meta: PageMeta;
  notice?: string | null;
  facets?: { categories: Category[]; conditions: Record<string, number>; selected_category: { name: string; slug: string; parent_id: number | null } | null };
};

export type CartLine = {
  product_id: number;
  variant_id: number | null;
  slug: string;
  title: string;
  variant: string | null;
  image: string | null;
  unit_price: number;
  list_unit_price: number;
  quantity: number;
  line_total: number;
  max: number;
};

export type Cart = {
  count: number;
  groups: { seller: { name: string; slug: string; logo: string | null; away: boolean } | null; subtotal: number; shipping: number; lines: CartLine[] }[];
  subtotal: number;
  shipping: number;
  shipping_free: boolean;
  free_threshold: number;
  coupon_discount: number;
  coupon_message: string | null;
  bundle_savings: number;
  bundles: { bundle_id: number; title: string; sets: number; savings: number }[];
  credits_available: number;
  blocked: string[];
  tax_note: string | null;
  fit: { vehicle: string | null; covered: number; total: number; lines: Record<string, boolean> };
  estimated_total: number;
  message?: string;
};

export type ApiErrorBody = { message?: string; code?: string; errors?: Record<string, string[]> };
