import axios from 'axios';

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api',
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
});

api.interceptors.request.use(
  (config) => {
    const token = localStorage.getItem('access_token');
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
  },
  (error) => Promise.reject(error)
);

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('access_token');
      localStorage.removeItem('user');
      window.location.href = '/login';
    }
    return Promise.reject(error);
  }
);

export const authService = {
  login: async (email, password) => {
    const response = await api.post('/login', { email, password });
    if (response.data.access_token) {
      localStorage.setItem('access_token', response.data.access_token);
      localStorage.setItem('user', JSON.stringify(response.data.user));
    }
    return response.data;
  },

  logout: async () => {
    try {
      await api.post('/logout');
    } finally {
      localStorage.removeItem('access_token');
      localStorage.removeItem('user');
      window.location.href = '/login';
    }
  },

  getCurrentUser: () => {
    const user = localStorage.getItem('user');
    return user ? JSON.parse(user) : null;
  },

  isAuthenticated: () => Boolean(localStorage.getItem('access_token')),

  storeUser: (user) => localStorage.setItem('user', JSON.stringify(user)),


  me: () => api.get('/me').then((res) => res.data),

  forgotPassword: (email) => api.post('/forgot-password', { email }).then((res) => res.data),

  resetPassword: (data) => api.post('/reset-password', data).then((res) => res.data),
};

export const customerService = {
  getAll: (params) => api.get('/customers', { params }).then((res) => res.data),
  lookup: (q) => api.get('/customers/lookup', { params: { q } }).then((res) => res.data),
  checkDuplicates: (data) => api.post('/customers/check-duplicates', data).then((res) => res.data),
  getById: (id) => api.get(`/customers/${id}`).then((res) => res.data),
  create: (data) => api.post('/customers', data).then((res) => res.data),
  update: (id, data) => api.put(`/customers/${id}`, data).then((res) => res.data),
  delete: (id) => api.delete(`/customers/${id}`).then((res) => res.data),
  verify: (id, data) => api.post(`/customers/${id}/verify`, data).then((res) => res.data),
  activate: (id) => api.post(`/customers/${id}/activate`).then((res) => res.data),
  changeStatus: (id, data) => api.post(`/customers/${id}/status`, data).then((res) => res.data),
  accountLogs: (id, params) => api.get(`/customers/${id}/account-logs`, { params }).then((res) => res.data),
  notifications: (id, params) => api.get(`/customers/${id}/notifications`, { params }).then((res) => res.data),
  purchases: (id, params) => api.get(`/customers/${id}/purchases`, { params }).then((res) => res.data),
  rewards: (id) => api.get(`/customers/${id}/rewards`).then((res) => res.data),
};

export const customerManagementService = {
  options: () => api.get('/customer-management/options').then((res) => res.data),
  dashboard: (params) => api.get('/customer-management/dashboard', { params }).then((res) => res.data),
  reports: (params) => api.get('/customer-management/reports', { params }).then((res) => res.data),
  announce: (data) => api.post('/customer-management/announcements', data).then((res) => res.data),
};

export const loyaltyService = {
  getSettings: () => api.get('/loyalty/settings').then((res) => res.data),
  updateSettings: (data) => api.put('/loyalty/settings', data).then((res) => res.data),
  updateLevel: (id, data) => api.put(`/loyalty/levels/${id}`, data).then((res) => res.data),
  calculate: (params) => api.get('/loyalty/calculate', { params }).then((res) => res.data),
  members: (params) => api.get('/loyalty/members', { params }).then((res) => res.data),
  transactions: (params) => api.get('/loyalty/transactions', { params }).then((res) => res.data),
  syncOrder: (orderId) => api.post(`/loyalty/orders/${orderId}/sync`).then((res) => res.data),
  runExpiry: () => api.post('/loyalty/expire').then((res) => res.data),
  customer: (id, params) => api.get(`/customers/${id}/loyalty`, { params }).then((res) => res.data),
  enroll: (id) => api.post(`/customers/${id}/loyalty/enroll`).then((res) => res.data),
  setLevel: (id, data) => api.post(`/customers/${id}/loyalty/level`, data).then((res) => res.data),
  adjust: (id, data) => api.post(`/customers/${id}/loyalty/adjust`, data).then((res) => res.data),
};

export const purchaseHistoryService = {
  getAll: (params) => api.get('/purchase-history', { params }).then((res) => res.data),
  verify: (orderId) => api.get(`/purchase-history/${orderId}/verify`).then((res) => res.data),
  associate: (orderId, data) =>
    api.post(`/purchase-history/${orderId}/associate-customer`, data).then((res) => res.data),
};

export const feedbackService = {
  getAll: (params) => api.get('/feedback', { params }).then((res) => res.data),
  getById: (id) => api.get(`/feedback/${id}`).then((res) => res.data),
  create: (data) => api.post('/feedback', data).then((res) => res.data),
  update: (id, data) => api.put(`/feedback/${id}`, data).then((res) => res.data),
  assign: (id, data) => api.post(`/feedback/${id}/assign`, data).then((res) => res.data),
  respond: (id, data) => api.post(`/feedback/${id}/responses`, data).then((res) => res.data),
  changeStatus: (id, data) => api.post(`/feedback/${id}/status`, data).then((res) => res.data),
};

export const rewardService = {
  getAll: (params) => api.get('/rewards', { params }).then((res) => res.data),
  create: (data) => api.post('/rewards', data).then((res) => res.data),
  update: (id, data) => api.put(`/rewards/${id}`, data).then((res) => res.data),
  delete: (id) => api.delete(`/rewards/${id}`).then((res) => res.data),
};

export const redemptionService = {
  getAll: (params) => api.get('/redemptions', { params }).then((res) => res.data),
  check: (data) => api.post('/redemptions/check', data).then((res) => res.data),
  create: (data) => api.post('/redemptions', data).then((res) => res.data),
  cancel: (id, data) => api.post(`/redemptions/${id}/cancel`, data).then((res) => res.data),
};

export const userService = {
  getAll: (params) => api.get('/users', { params }).then((res) => res.data),
  create: (data) => api.post('/users', data).then((res) => res.data),
  update: (id, data) => api.put(`/users/${id}`, data).then((res) => res.data),
  deactivate: (id) => api.delete(`/users/${id}`).then((res) => res.data),
};

export const branchService = {
  getAll: (params) => api.get('/branches', { params }).then((res) => res.data),
  create: (data) => api.post('/branches', data).then((res) => res.data),
  update: (id, data) => api.put(`/branches/${id}`, data).then((res) => res.data),
};

export const productService = {
  getAll: (params) => api.get('/products', { params }).then((res) => res.data),
  getLowStock: (threshold = 10) =>
    api.get('/products/low-stock', { params: { threshold } }).then((res) => res.data),
  getById: (id) => api.get(`/products/${id}`).then((res) => res.data),
  create: (data) => api.post('/products', data).then((res) => res.data),
  update: (id, data) => api.put(`/products/${id}`, data).then((res) => res.data),
  delete: (id) => api.delete(`/products/${id}`).then((res) => res.data),
};

export const orderService = {
  getAll: (params) => api.get('/orders', { params }).then((res) => res.data),
  getById: (id) => api.get(`/orders/${id}`).then((res) => res.data),
  create: (data) => api.post('/orders', data).then((res) => res.data),
  updateStatus: (id, status) =>
    api.put(`/orders/${id}`, { order_status: status }).then((res) => res.data),
  getSalesStats: (params) => api.get('/orders/stats/sales', { params }).then((res) => res.data),
};

export const inventoryService = {
  getAll: (params) => api.get('/inventory', { params }).then((res) => res.data),
  getSummary: () => api.get('/inventory/summary').then((res) => res.data),
  create: (data) => api.post('/inventory', data).then((res) => res.data),
  update: (id, data) => api.put(`/inventory/${id}`, data).then((res) => res.data),
  updateStock: (id, data) =>
    api.post(`/inventory/${id}/update-stock`, data).then((res) => res.data),
  transferStock: (data) => api.post('/inventory/transfer', data).then((res) => res.data),
  delete: (id) => api.delete(`/inventory/${id}`).then((res) => res.data),
};

export const warehouseService = {
  getAll: () => api.get('/warehouses').then((res) => res.data),
  getById: (id) => api.get(`/warehouses/${id}`).then((res) => res.data),
  create: (data) => api.post('/warehouses', data).then((res) => res.data),
  update: (id, data) => api.put(`/warehouses/${id}`, data).then((res) => res.data),
  delete: (id) => api.delete(`/warehouses/${id}`).then((res) => res.data),
};

export default api;
