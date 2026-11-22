export const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api';

export const ROLES = {
  ADMIN: 'Admin',
  MANAGER: 'Manager',
  CUSTOMER_SERVICE: 'Customer Service',
  MARKETING: 'Marketing',
  SALES: 'Sales',
  WAREHOUSE: 'Warehouse',
};

// Validated categorical chart palette, assigned in fixed order (never cycled).
export const SERIES = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];

// Loyalty ledger entry types
export const LEDGER_TYPES = {
  earn: { label: 'Earned', color: 'success' },
  redeem: { label: 'Redeemed', color: 'secondary' },
  adjustment: { label: 'Adjustment', color: 'info' },
  reversal: { label: 'Reversal', color: 'warning' },
  refund: { label: 'Refund', color: 'primary' },
  expiry: { label: 'Expired', color: 'default' },
};

export const ORDER_STATUS = {
  PENDING: 'Pending',
  PROCESSING: 'Processing',
  SHIPPED: 'Shipped',
  DELIVERED: 'Delivered',
  CANCELLED: 'Cancelled',
};

export const PAYMENT_METHODS = [
  'Cash',
  'Credit Card',
  'Debit Card',
  'Bank Transfer',
  'Check',
];

export const PRODUCT_CATEGORIES = [
  'Electronics',
  'Clothing',
  'Furniture',
  'Books',
  'Food & Beverages',
  'Healthcare',
  'Beauty',
  'Sports',
  'Automotive',
  'Other',
];
