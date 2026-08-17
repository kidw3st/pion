export interface CartItem {
  uid: string;
  title: string;
  price: number;
  image: string;
  quantity: number;
}

export interface CartState {
  items: CartItem[];
}

export type CartAction =
  | { type: 'ADD_ITEM'; item: Omit<CartItem, 'quantity'> }
  | { type: 'REMOVE_ITEM'; uid: string }
  | { type: 'SET_QUANTITY'; uid: string; quantity: number }
  | { type: 'CLEAR' }
  | { type: 'HYDRATE'; items: CartItem[] };

export function cartReducer(state: CartState, action: CartAction): CartState {
  switch (action.type) {
    case 'ADD_ITEM': {
      const existing = state.items.find((i) => i.uid === action.item.uid);
      if (existing) {
        return {
          items: state.items.map((i) =>
            i.uid === action.item.uid ? { ...i, quantity: i.quantity + 1 } : i
          ),
        };
      }
      return { items: [...state.items, { ...action.item, quantity: 1 }] };
    }
    case 'REMOVE_ITEM':
      return { items: state.items.filter((i) => i.uid !== action.uid) };
    case 'SET_QUANTITY': {
      if (action.quantity <= 0) {
        return { items: state.items.filter((i) => i.uid !== action.uid) };
      }
      return {
        items: state.items.map((i) =>
          i.uid === action.uid ? { ...i, quantity: action.quantity } : i
        ),
      };
    }
    case 'CLEAR':
      return { items: [] };
    case 'HYDRATE':
      return { items: sanitizeItems(action.items) };
    default:
      return state;
  }
}

/**
 * HYDRATE is the one action whose payload does not come from our own code:
 * it is whatever localStorage holds, which anything running on the origin —
 * an extension, an old buggy build, a person in DevTools — may have rewritten.
 * Rendering trusts these fields (`price * quantity` lands in the order total),
 * so anything malformed is dropped rather than carried into state.
 */
function sanitizeItems(raw: unknown): CartItem[] {
  if (!Array.isArray(raw)) return [];
  const items: CartItem[] = [];
  for (const entry of raw) {
    if (typeof entry !== 'object' || entry === null) continue;
    const { uid, title, price, image, quantity } = entry as Record<string, unknown>;
    if (typeof uid !== 'string' || uid === '' || typeof title !== 'string') continue;
    if (typeof image !== 'string') continue;
    if (typeof price !== 'number' || !Number.isFinite(price) || price < 0) continue;
    if (typeof quantity !== 'number' || !Number.isInteger(quantity) || quantity < 1) continue;
    if (items.some((i) => i.uid === uid)) continue;
    // A hand-edited quantity of 1e9 would make every total nonsensical.
    items.push({ uid, title, price, image, quantity: Math.min(quantity, 99) });
  }
  return items;
}

export function cartTotal(state: CartState): number {
  return state.items.reduce((sum, i) => sum + i.price * i.quantity, 0);
}
