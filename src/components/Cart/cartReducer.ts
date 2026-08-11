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
  | { type: 'CLEAR' };

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
    default:
      return state;
  }
}

export function cartTotal(state: CartState): number {
  return state.items.reduce((sum, i) => sum + i.price * i.quantity, 0);
}
