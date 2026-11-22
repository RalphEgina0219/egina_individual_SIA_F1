import React from 'react';
import { BrowserRouter as Router, Routes, Route, Navigate } from 'react-router-dom';
import { ThemeProvider, createTheme } from '@mui/material/styles';
import CssBaseline from '@mui/material/CssBaseline';
import { AuthProvider, useAuth } from './context/AuthContext';
import { NotifyProvider } from './context/NotifyContext';
import ProtectedRoute from './components/ProtectedRoute';
import MainLayout from './layouts/MainLayout';

// Pages
import Login from './pages/Login';
import ResetPassword from './pages/ResetPassword';
import Dashboard from './pages/Dashboard';
import Products from './pages/Products';
import ProductDetail from './pages/ProductDetail';
import Warehouses from './pages/Warehouses';
import Inventory from './pages/Inventory';
import Orders from './pages/Orders';
import Reports from './pages/Reports';
import Settings from './pages/Settings';

// Customer Management Module
import CustomerDashboard from './pages/customers/CustomerDashboard';
import CustomerList from './pages/customers/CustomerList';
import CustomerRegistration from './pages/customers/CustomerRegistration';
import CustomerProfile from './pages/customers/CustomerProfile';
import LoyaltyMembership from './pages/customers/LoyaltyMembership';
import LoyaltyPoints from './pages/customers/LoyaltyPoints';
import PurchaseHistory from './pages/customers/PurchaseHistory';
import FeedbackManagement from './pages/customers/FeedbackManagement';
import RewardRedemption from './pages/customers/RewardRedemption';
import CustomerReports from './pages/customers/CustomerReports';

const theme = createTheme({
  palette: {
    primary: {
      main: '#1a237e',
    },
    secondary: {
      main: '#ff4081',
    },
  },
});

// `permission` follows the backend permission map (App\Support\Permissions).
const routes = [
  { path: '/', element: <Dashboard /> },

  { path: '/customer-management', element: <CustomerDashboard />, permission: 'customers.dashboard' },
  { path: '/customers', element: <CustomerList />, permission: 'customers.view' },
  { path: '/customers/register', element: <CustomerRegistration />, permission: 'customers.create' },
  { path: '/customers/:id', element: <CustomerProfile />, permission: 'customers.view' },
  { path: '/loyalty/membership', element: <LoyaltyMembership />, permission: 'loyalty.view' },
  { path: '/loyalty/points', element: <LoyaltyPoints />, permission: 'loyalty.view' },
  { path: '/purchase-history', element: <PurchaseHistory />, permission: 'customers.purchases' },
  { path: '/feedback', element: <FeedbackManagement />, permission: 'feedback.view' },
  // Open to all staff: users without feedback.view only see concerns assigned to them.
  { path: '/complaints', element: <FeedbackManagement key="complaints" complaintsOnly /> },
  { path: '/rewards', element: <RewardRedemption />, permission: ['redemptions.view', 'rewards.view'] },
  { path: '/customer-reports', element: <CustomerReports />, permission: 'customers.reports' },

  { path: '/products', element: <Products />, permission: 'products.view' },
  { path: '/products/:id', element: <ProductDetail />, permission: 'products.view' },
  { path: '/warehouses', element: <Warehouses />, permission: 'inventory.manage' },
  { path: '/inventory', element: <Inventory />, permission: 'inventory.manage' },
  { path: '/orders', element: <Orders />, permission: 'orders.view' },
  { path: '/reports', element: <Reports />, permission: 'reports.sales' },
  { path: '/settings', element: <Settings />, permission: 'users.manage' },
];

const AppContent = () => {
  const { isAuthenticated } = useAuth();

  return (
    <Routes>
      <Route path="/login" element={!isAuthenticated ? <Login /> : <Navigate to="/" replace />} />
      <Route path="/reset-password" element={<ResetPassword />} />

      {routes.map(({ path, element, permission }) => (
        <Route
          key={path}
          path={path}
          element={
            <ProtectedRoute permission={permission}>
              <MainLayout>{element}</MainLayout>
            </ProtectedRoute>
          }
        />
      ))}

      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
};

function App() {
  return (
    <ThemeProvider theme={theme}>
      <CssBaseline />
      <NotifyProvider>
        <Router>
          <AuthProvider>
            <AppContent />
          </AuthProvider>
        </Router>
      </NotifyProvider>
    </ThemeProvider>
  );
}

export default App;
