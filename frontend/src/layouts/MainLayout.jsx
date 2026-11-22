import React, { useState } from 'react';
import {
  AppBar,
  Box,
  Collapse,
  CssBaseline,
  Drawer,
  IconButton,
  List,
  ListItemButton,
  ListItemIcon,
  ListItemText,
  ListSubheader,
  Toolbar,
  Typography,
  Avatar,
  Menu,
  MenuItem,
  Divider,
  useMediaQuery,
} from '@mui/material';
import { useTheme } from '@mui/material/styles';
import {
  Menu as MenuIcon,
  Dashboard,
  People,
  Inventory,
  Warehouse,
  ShoppingCart,
  Assessment,
  Settings,
  ChevronLeft,
  Logout,
  Insights,
  PersonAdd,
  CardMembership,
  Stars,
  ReceiptLong,
  Feedback,
  ReportProblem,
  Redeem,
  Summarize,
  ExpandLess,
  ExpandMore,
  Storefront,
} from '@mui/icons-material';
import { useNavigate, useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

const drawerWidth = 260;

// `permission` (any of) mirrors the backend permission map.
const sections = [
  {
    title: null,
    items: [
      { text: 'Dashboard', icon: <Dashboard />, path: '/' },
    ],
  },
  {
    title: 'Customer Management',
    collapsible: true,
    items: [
      { text: 'Customer Dashboard', icon: <Insights />, path: '/customer-management', permission: 'customers.dashboard' },
      { text: 'All Customers', icon: <People />, path: '/customers', permission: 'customers.view' },
      { text: 'Customer Registration', icon: <PersonAdd />, path: '/customers/register', permission: 'customers.create' },
      { text: 'Loyalty Membership', icon: <CardMembership />, path: '/loyalty/membership', permission: 'loyalty.view' },
      { text: 'Loyalty Points', icon: <Stars />, path: '/loyalty/points', permission: 'loyalty.view' },
      { text: 'Purchase History', icon: <ReceiptLong />, path: '/purchase-history', permission: 'customers.purchases' },
      { text: 'Feedback Management', icon: <Feedback />, path: '/feedback', permission: 'feedback.view' },
      { text: 'Complaint Tracking', icon: <ReportProblem />, path: '/complaints' },
      { text: 'Reward Redemption', icon: <Redeem />, path: '/rewards', permission: ['redemptions.view', 'rewards.view'] },
      { text: 'Customer Reports', icon: <Summarize />, path: '/customer-reports', permission: 'customers.reports' },
    ],
  },
  {
    title: 'Sales & Inventory',
    items: [
      { text: 'Products', icon: <Inventory />, path: '/products', permission: 'products.view' },
      { text: 'Warehouses', icon: <Warehouse />, path: '/warehouses', permission: 'inventory.manage' },
      { text: 'Inventory', icon: <Warehouse />, path: '/inventory', permission: 'inventory.manage' },
      { text: 'Orders', icon: <ShoppingCart />, path: '/orders', permission: 'orders.view' },
      { text: 'Sales Reports', icon: <Assessment />, path: '/reports', permission: 'reports.sales' },
    ],
  },
  {
    title: 'Administration',
    items: [
      { text: 'Settings', icon: <Settings />, path: '/settings', permission: 'users.manage' },
    ],
  },
];

const MainLayout = ({ children }) => {
  const theme = useTheme();
  const isDesktop = useMediaQuery(theme.breakpoints.up('md'));
  const [desktopOpen, setDesktopOpen] = useState(true);
  const [mobileOpen, setMobileOpen] = useState(false);
  const [customerOpen, setCustomerOpen] = useState(true);
  const [anchorEl, setAnchorEl] = useState(null);
  const navigate = useNavigate();
  const location = useLocation();
  const { user, logout, can } = useAuth();

  const drawerOpen = isDesktop ? desktopOpen : mobileOpen;

  const handleDrawerToggle = () => {
    if (isDesktop) setDesktopOpen(!desktopOpen);
    else setMobileOpen(!mobileOpen);
  };

  const handleMenuOpen = (event) => {
    setAnchorEl(event.currentTarget);
  };

  const handleMenuClose = () => {
    setAnchorEl(null);
  };

  const handleLogout = () => {
    logout();
    handleMenuClose();
  };

  const handleNavigate = (path) => {
    navigate(path);
    if (!isDesktop) setMobileOpen(false);
  };

  // Most specific match wins, so /customers/register does not also highlight /customers.
  const allPaths = sections.flatMap((s) => s.items.map((i) => i.path));
  const activePath = allPaths
    .filter((p) => location.pathname === p || (p !== '/' && location.pathname.startsWith(`${p}/`)))
    .sort((a, b) => b.length - a.length)[0];

  const visibleSections = sections
    .map((section) => ({ ...section, items: section.items.filter((item) => !item.permission || can(item.permission)) }))
    .filter((section) => section.items.length > 0);

  const renderItem = (item, nested = false) => {
    const selected = activePath === item.path;
    return (
      <ListItemButton
        key={item.path}
        onClick={() => handleNavigate(item.path)}
        selected={selected}
        sx={{
          pl: nested ? 3 : 2,
          py: 0.75,
          '&.Mui-selected': {
            backgroundColor: '#e3f2fd',
            color: '#1a237e',
            '&:hover': {
              backgroundColor: '#bbdefb',
            },
          },
        }}
      >
        <ListItemIcon sx={{ minWidth: 40, color: selected ? '#1a237e' : 'inherit' }}>
          {item.icon}
        </ListItemIcon>
        <ListItemText primary={item.text} slotProps={{ primary: { fontSize: '0.9rem' } }} />
      </ListItemButton>
    );
  };

  const drawerContent = (
    <>
      <Toolbar />
      <Box sx={{ overflow: 'auto', pb: 2 }}>
        {visibleSections.map((section) => (
          <List
            key={section.title || 'main'}
            dense
            subheader={section.title && (
              <ListSubheader
                component="div"
                onClick={section.collapsible ? () => setCustomerOpen(!customerOpen) : undefined}
                sx={{
                  lineHeight: '36px',
                  fontWeight: 600,
                  cursor: section.collapsible ? 'pointer' : 'default',
                  display: 'flex',
                  justifyContent: 'space-between',
                  alignItems: 'center',
                }}
              >
                {section.title}
                {section.collapsible && (customerOpen ? <ExpandLess fontSize="small" /> : <ExpandMore fontSize="small" />)}
              </ListSubheader>
            )}
          >
            {section.collapsible ? (
              <Collapse in={customerOpen} timeout="auto" unmountOnExit>
                {section.items.map((item) => renderItem(item, true))}
              </Collapse>
            ) : (
              section.items.map((item) => renderItem(item))
            )}
          </List>
        ))}
      </Box>
    </>
  );

  return (
    <Box sx={{ display: 'flex' }}>
      <CssBaseline />

      {/* AppBar */}
      <AppBar
        position="fixed"
        sx={{
          zIndex: (theme) => theme.zIndex.drawer + 1,
          backgroundColor: '#1a237e',
        }}
      >
        <Toolbar>
          <IconButton
            color="inherit"
            edge="start"
            onClick={handleDrawerToggle}
            sx={{ mr: 2 }}
            aria-label="Toggle navigation"
          >
            {drawerOpen ? <ChevronLeft /> : <MenuIcon />}
          </IconButton>

          <Storefront sx={{ mr: 1, display: { xs: 'none', sm: 'block' } }} />
          <Typography variant="h6" noWrap component="div" sx={{ flexGrow: 1 }}>
            ShopLink Supermarket
            <Typography component="span" variant="body2" sx={{ ml: 1, opacity: 0.75, display: { xs: 'none', md: 'inline' } }}>
              Management System
            </Typography>
          </Typography>

          <Box sx={{ display: 'flex', alignItems: 'center', gap: 2 }}>
            <Typography variant="body2" sx={{ display: { xs: 'none', sm: 'block' } }}>
              {user?.name} ({user?.role})
            </Typography>
            <IconButton onClick={handleMenuOpen} size="small" aria-label="Account menu">
              <Avatar sx={{ width: 32, height: 32, bgcolor: '#4a148c' }}>
                {user?.name?.charAt(0)}
              </Avatar>
            </IconButton>
          </Box>

          <Menu
            anchorEl={anchorEl}
            open={Boolean(anchorEl)}
            onClose={handleMenuClose}
          >
            <MenuItem disabled>
              <Box>
                <Typography variant="body2">{user?.name}</Typography>
                <Typography variant="caption">{user?.email} · {user?.role}</Typography>
              </Box>
            </MenuItem>
            <Divider />
            <MenuItem onClick={handleLogout}>
              <ListItemIcon>
                <Logout fontSize="small" />
              </ListItemIcon>
              Logout
            </MenuItem>
          </Menu>
        </Toolbar>
      </AppBar>

      {/* Drawer */}
      <Drawer
        variant={isDesktop ? 'persistent' : 'temporary'}
        open={drawerOpen}
        onClose={() => setMobileOpen(false)}
        ModalProps={{ keepMounted: true }}
        sx={{
          width: isDesktop && drawerOpen ? drawerWidth : 0,
          flexShrink: 0,
          '& .MuiDrawer-paper': {
            width: drawerWidth,
            boxSizing: 'border-box',
          },
        }}
      >
        {drawerContent}
      </Drawer>

      {/* Main Content */}
      <Box
        component="main"
        sx={{
          flexGrow: 1,
          p: { xs: 2, md: 3 },
          minWidth: 0,
        }}
      >
        <Toolbar />
        {children}
      </Box>
    </Box>
  );
};

export default MainLayout;
