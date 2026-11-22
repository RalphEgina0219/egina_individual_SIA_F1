import { format } from 'date-fns';

// Date formatting
export const formatDate = (date, formatStr = 'dd/MM/yyyy') => {
  if (!date) return '';
  return format(new Date(date), formatStr);
};

export const formatDateTime = (date) => formatDate(date, 'dd/MM/yyyy HH:mm');

// Currency Formatting (ShopLink operates in Philippine Peso)
export const formatCurrency = (amount, currency = 'PHP') => {
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: currency,
  }).format(Number(amount) || 0);
};

export const formatNumber = (value) => new Intl.NumberFormat('en-PH').format(Number(value) || 0);

// Cut the text and add ...
export const truncateText = (text, length = 50) => {
  if (!text) return '';
  if (text.length <= length) return text;
  return text.substring(0, length) + '...';
};

// Check permissions
export const hasPermission = (user, requiredRole) => {
  if (!user || !user.role) return false;
  return user.role === requiredRole;
};

// Converting data for Formik
export const prepareFormData = (data) => {
  const formData = new FormData();
  Object.keys(data).forEach(key => {
    if (data[key] !== null && data[key] !== undefined) {
      formData.append(key, data[key]);
    }
  });
  return formData;
};

/**
 * Extract a readable message and per-field validation errors from an API error.
 */
export const apiError = (error, fallback = 'Something went wrong') => {
  const data = error?.response?.data || {};
  const fieldErrors = Object.fromEntries(
    Object.entries(data.errors || {}).map(([field, messages]) => [field, messages[0]])
  );
  return {
    message: data.message || fallback,
    fieldErrors,
    status: error?.response?.status,
    data: data.data,
  };
};

/**
 * Drop empty values so they are not sent as filters.
 */
export const cleanParams = (params) =>
  Object.fromEntries(Object.entries(params).filter(([, v]) => v !== '' && v !== null && v !== undefined));

export const newIdempotencyKey = () =>
  (window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(36).slice(2)}`);

/**
 * Download rows as a CSV file (used by Customer Reports).
 */
export const downloadCsv = (filename, rows) => {
  if (!rows.length) return;
  const headers = Object.keys(rows[0]);
  const escape = (value) => `"${String(value ?? '').replace(/"/g, '""')}"`;
  const csv = [headers.join(','), ...rows.map((row) => headers.map((h) => escape(row[h])).join(','))].join('\n');
  const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8;' }));
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  link.click();
  URL.revokeObjectURL(url);
};
