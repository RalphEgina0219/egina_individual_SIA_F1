import React, { useState, useEffect } from 'react';
import {
  Box,
  Button,
  Paper,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  TablePagination,
  TextField,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  IconButton,
  Chip,
  Typography,
  FormControl,
  InputLabel,
  Select,
  MenuItem,
  Alert,
  Snackbar,
  Tooltip,
  Card,
  CardContent,
  Stepper,
  Step,
  StepLabel,
  Autocomplete,
  Divider,
} from '@mui/material';
import {
  Add as AddIcon,
  Search as SearchIcon,
  Visibility as VisibilityIcon,
  Delete as DeleteIcon,
  LocalShipping as ShippingIcon,
  CheckCircle as CheckCircleIcon,
  Cancel as CancelIcon,
  Pending as PendingIcon,
} from '@mui/icons-material';
import Grid from '@mui/material/Grid';
import { orderService, productService } from '../services/api';
import CustomerPicker from '../components/common/CustomerPicker';
import { useOptions } from '../hooks/useOptions';
import { formatCurrency } from '../utils/helpers';
import { DatePicker } from '@mui/x-date-pickers/DatePicker';
import { LocalizationProvider } from '@mui/x-date-pickers/LocalizationProvider';
import { AdapterDateFns } from '@mui/x-date-pickers/AdapterDateFns';

const Orders = () => {
  const [orders, setOrders] = useState([]);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(0);
  const [rowsPerPage, setRowsPerPage] = useState(10);
  const [searchTerm, setSearchTerm] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [startDate, setStartDate] = useState(null);
  const [endDate, setEndDate] = useState(null);
  const [openDialog, setOpenDialog] = useState(false);
  const [viewDialog, setViewDialog] = useState(false);
  const [selectedOrder, setSelectedOrder] = useState(null);
  const [selectedCustomer, setSelectedCustomer] = useState(null);
  const options = useOptions();
  const [products, setProducts] = useState([]);
  const [orderItems, setOrderItems] = useState([{ product_id: '', quantity: 1 }]);
  const [formData, setFormData] = useState({
    branch_id: '',
    payment_method: 'Cash',
  });
  const [snackbar, setSnackbar] = useState({ open: false, message: '', severity: 'success' });

  // Fetch data
  const fetchData = async () => {
    setLoading(true);
    try {
      const [ordersRes, productsRes] = await Promise.all([
        orderService.getAll({ per_page: 1000 }),
        productService.getAll({ per_page: 1000 }),
      ]);

      setOrders(ordersRes.data?.data || []);
      setProducts(productsRes.data?.data || []);
    } catch (error) {
      console.error('Error fetching data:', error);
      setSnackbar({ open: true, message: 'Failed to load data', severity: 'error' });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchData();
  }, []);

  // Filter orders
  const filteredOrders = orders.filter(order => {
    const matchesSearch =
      order.id.toString().includes(searchTerm) ||
      order.transaction_number?.toLowerCase().includes(searchTerm.toLowerCase()) ||
      order.customer?.full_name?.toLowerCase().includes(searchTerm.toLowerCase()) ||
      order.customer?.customer_code?.toLowerCase().includes(searchTerm.toLowerCase());

    const matchesStatus = statusFilter === 'all' || order.order_status === statusFilter;

    const orderDate = new Date(order.order_date);
    const matchesDate = (!startDate || orderDate >= startDate) &&
      (!endDate || orderDate <= endDate);

    return matchesSearch && matchesStatus && matchesDate;
  });

  // Handle dialog open for new order
  const handleOpenNewDialog = () => {
    setFormData({
      branch_id: '',
      payment_method: 'Cash',
    });
    setSelectedCustomer(null);
    setOrderItems([{ product_id: '', quantity: 1 }]);
    setOpenDialog(true);
  };

  // Handle view order details
  const handleViewOrder = (order) => {
    setSelectedOrder(order);
    setViewDialog(true);
  };

  // Handle form input change
  const handleInputChange = (e) => {
    const { name, value } = e.target;
    setFormData(prev => ({
      ...prev,
      [name]: value,
    }));
  };

  // Handle order item change
  const handleOrderItemChange = (index, field, value) => {
    const newItems = [...orderItems];
    newItems[index] = { ...newItems[index], [field]: value };
    setOrderItems(newItems);
  };

  // Add new order item
  const addOrderItem = () => {
    setOrderItems([...orderItems, { product_id: '', quantity: 1 }]);
  };

  // Remove order item
  const removeOrderItem = (index) => {
    if (orderItems.length > 1) {
      const newItems = orderItems.filter((_, i) => i !== index);
      setOrderItems(newItems);
    }
  };

  // Handle create order
  const handleCreateOrder = async () => {
    // Validate items
    const validItems = orderItems.filter(item => item.product_id && item.quantity > 0);
    if (validItems.length === 0) {
      setSnackbar({ open: true, message: 'Please add at least one product', severity: 'error' });
      return;
    }

    try {
      const orderData = {
        ...formData,
        customer_id: selectedCustomer?.id || null,
        branch_id: formData.branch_id || null,
        items: validItems.map(item => ({
          product_id: parseInt(item.product_id),
          quantity: parseInt(item.quantity),
        })),
      };

      await orderService.create(orderData);
      setSnackbar({ open: true, message: 'Order created successfully', severity: 'success' });
      setOpenDialog(false);
      fetchData();
    } catch (error) {
      const message = error.response?.data?.message || 'Failed to create order';
      setSnackbar({ open: true, message, severity: 'error' });
    }
  };

  // Handle update order status
  const handleUpdateStatus = async (orderId, newStatus) => {
    try {
      const res = await orderService.updateStatus(orderId, newStatus);
      let message = 'Order status updated';
      if (res.loyalty?.awarded) message += ` - ${res.loyalty.points} loyalty points earned`;
      else if (res.loyalty?.reversed_points) message += ` - ${res.loyalty.reversed_points} loyalty points reversed`;
      setSnackbar({ open: true, message, severity: 'success' });
      fetchData();
    } catch (error) {
      const message = error.response?.data?.message || 'Failed to update status';
      setSnackbar({ open: true, message, severity: 'error' });
    }
  };

  // Handle delete order
  const handleDeleteOrder = async (orderId) => {
    if (window.confirm('Are you sure you want to delete this order?')) {
      try {
        await orderService.updateStatus(orderId, 'Cancelled');
        setSnackbar({ open: true, message: 'Order cancelled', severity: 'success' });
        fetchData();
      } catch (error) {
        const message = error.response?.data?.message || 'Failed to cancel order';
        setSnackbar({ open: true, message, severity: 'error' });
      }
    }
  };

  // Get status icon
  const getStatusIcon = (status) => {
    switch (status) {
      case 'Pending': return <PendingIcon color="warning" />;
      case 'Processing': return <ShippingIcon color="info" />;
      case 'Shipped': return <ShippingIcon color="primary" />;
      case 'Delivered': return <CheckCircleIcon color="success" />;
      case 'Cancelled': return <CancelIcon color="error" />;
      default: return null;
    }
  };

  // Get status color
  const getStatusColor = (status) => {
    switch (status) {
      case 'Pending': return 'warning';
      case 'Processing': return 'info';
      case 'Shipped': return 'primary';
      case 'Delivered': return 'success';
      case 'Cancelled': return 'error';
      default: return 'default';
    }
  };

  // Calculate total for order items
  const calculateTotal = () => {
    return orderItems.reduce((total, item) => {
      const product = products.find(p => p.id === parseInt(item.product_id));
      return total + (product ? product.unit_price * item.quantity : 0);
    }, 0);
  };

  // Handle pagination
  const handleChangePage = (event, newPage) => {
    setPage(newPage);
  };

  const handleChangeRowsPerPage = (event) => {
    setRowsPerPage(parseInt(event.target.value, 10));
    setPage(0);
  };

  // Paginated orders
  const paginatedOrders = filteredOrders.slice(
    page * rowsPerPage,
    page * rowsPerPage + rowsPerPage
  );

  // Order status steps
  const statusSteps = ['Pending', 'Processing', 'Shipped', 'Delivered'];

  return (
    <LocalizationProvider dateAdapter={AdapterDateFns}>
      <Box>
        {/* Header */}
        <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 3 }}>
          <Typography variant="h4" sx={{ color: '#1a237e' }}>
            Orders Management
          </Typography>
          <Button
            variant="contained"
            startIcon={<AddIcon />}
            onClick={handleOpenNewDialog}
            sx={{ backgroundColor: '#1a237e' }}
          >
            New Order
          </Button>
        </Box>

        {/* Filters */}
        <Paper sx={{ p: 2, mb: 3 }}>
          <Grid container spacing={2} alignItems="center">
            <Grid item xs={12} md={3}>
              <TextField
                fullWidth
                placeholder="Transaction no. or customer..."
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
                InputProps={{
                  startAdornment: <SearchIcon sx={{ mr: 1, color: 'text.secondary' }} />,
                }}
              />
            </Grid>
            <Grid item xs={12} md={2}>
              <FormControl fullWidth>
                <InputLabel>Status</InputLabel>
                <Select
                  value={statusFilter}
                  label="Status"
                  onChange={(e) => setStatusFilter(e.target.value)}
                >
                  <MenuItem value="all">All Statuses</MenuItem>
                  <MenuItem value="Pending">Pending</MenuItem>
                  <MenuItem value="Processing">Processing</MenuItem>
                  <MenuItem value="Shipped">Shipped</MenuItem>
                  <MenuItem value="Delivered">Delivered</MenuItem>
                  <MenuItem value="Cancelled">Cancelled</MenuItem>
                </Select>
              </FormControl>
            </Grid>
            <Grid item xs={12} md={3}>
              <DatePicker
                label="Start Date"
                value={startDate}
                onChange={setStartDate}
                slotProps={{ textField: { fullWidth: true } }}
              />
            </Grid>
            <Grid item xs={12} md={3}>
              <DatePicker
                label="End Date"
                value={endDate}
                onChange={setEndDate}
                slotProps={{ textField: { fullWidth: true } }}
              />
            </Grid>
            <Grid item xs={12} md={1}>
              <Button
                variant="outlined"
                onClick={() => {
                  setStartDate(null);
                  setEndDate(null);
                  setStatusFilter('all');
                  setSearchTerm('');
                }}
              >
                Clear
              </Button>
            </Grid>
          </Grid>
        </Paper>

        {/* Orders Table */}
        <TableContainer component={Paper}>
          <Table>
            <TableHead>
              <TableRow sx={{ backgroundColor: '#f5f5f5' }}>
                <TableCell><strong>Order ID</strong></TableCell>
                <TableCell><strong>Customer</strong></TableCell>
                <TableCell><strong>Date</strong></TableCell>
                <TableCell align="right"><strong>Amount</strong></TableCell>
                <TableCell><strong>Status</strong></TableCell>
                <TableCell><strong>Payment</strong></TableCell>
                <TableCell align="center"><strong>Actions</strong></TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {loading ? (
                <TableRow>
                  <TableCell colSpan={7} align="center" sx={{ py: 3 }}>
                    Loading orders...
                  </TableCell>
                </TableRow>
              ) : paginatedOrders.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={7} align="center" sx={{ py: 3 }}>
                    No orders found
                  </TableCell>
                </TableRow>
              ) : (
                paginatedOrders.map((order) => (
                  <TableRow key={order.id} hover>
                    <TableCell>
                      <Typography variant="subtitle2" color="primary">
                        {order.transaction_number || `#${order.id}`}
                      </Typography>
                      {order.branch && (
                        <Typography variant="caption" color="textSecondary">{order.branch.name}</Typography>
                      )}
                    </TableCell>
                    <TableCell>
                      {order.customer ? (
                        <>
                          <Typography variant="body2">{order.customer.full_name}</Typography>
                          <Typography variant="caption" color="textSecondary">{order.customer.customer_code}</Typography>
                        </>
                      ) : (
                        <Chip label="Walk-in" size="small" variant="outlined" />
                      )}
                    </TableCell>
                    <TableCell>
                      {new Date(order.order_date).toLocaleDateString()}
                    </TableCell>
                    <TableCell align="right">
                      <Typography variant="subtitle2" color="primary">
                        {formatCurrency(order.total_amount)}
                      </Typography>
                    </TableCell>
                    <TableCell>
                      <Chip
                        icon={getStatusIcon(order.order_status)}
                        label={order.order_status}
                        color={getStatusColor(order.order_status)}
                        size="small"
                      />
                      {order.stock_sync_status && order.stock_sync_status !== 'not_sent' && (
                        <Tooltip title="Stock sync with the Inventory system (INV)">
                          <Chip
                            label={`INV: ${order.stock_sync_status}`}
                            size="small"
                            variant="outlined"
                            color={{ synced: 'success', pending: 'warning', failed: 'error' }[order.stock_sync_status] || 'default'}
                            sx={{ ml: 0.5 }}
                          />
                        </Tooltip>
                      )}
                    </TableCell>
                    <TableCell>
                      <Chip label={order.payment_method} size="small" variant="outlined" />
                    </TableCell>
                    <TableCell align="center">
                      <Box sx={{ display: 'flex', justifyContent: 'center', gap: 1 }}>
                        <Tooltip title="View Details">
                          <IconButton
                            size="small"
                            color="info"
                            onClick={() => handleViewOrder(order)}
                          >
                            <VisibilityIcon />
                          </IconButton>
                        </Tooltip>
                        {order.order_status !== 'Cancelled' && order.order_status !== 'Delivered' && (
                          <>
                            {statusSteps.map((status, index) => (
                              statusSteps.indexOf(order.order_status) < index && (
                                <Tooltip key={status} title={`Mark as ${status}`}>
                                  <IconButton
                                    size="small"
                                    color="primary"
                                    onClick={() => handleUpdateStatus(order.id, status)}
                                  >
                                    {getStatusIcon(status)}
                                  </IconButton>
                                </Tooltip>
                              )
                            ))}
                          </>
                        )}
                        {order.order_status !== 'Cancelled' && (
                          <Tooltip title="Cancel Order">
                            <IconButton
                              size="small"
                              color="error"
                              onClick={() => handleDeleteOrder(order.id)}
                            >
                              <CancelIcon />
                            </IconButton>
                          </Tooltip>
                        )}
                      </Box>
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        </TableContainer>

        {/* Pagination */}
        <TablePagination
          rowsPerPageOptions={[5, 10, 25]}
          component="div"
          count={filteredOrders.length}
          rowsPerPage={rowsPerPage}
          page={page}
          onPageChange={handleChangePage}
          onRowsPerPageChange={handleChangeRowsPerPage}
        />

        {/* New Order Dialog */}
        <Dialog open={openDialog} onClose={() => setOpenDialog(false)} maxWidth="md" fullWidth>
          <DialogTitle>Create New Order</DialogTitle>
          <DialogContent>
            <Box sx={{ pt: 2 }}>
              <Grid container spacing={3}>
                {/* Customer Selection */}
                <Grid size={{ xs: 12, md: 8 }}>
                  <CustomerPicker
                    value={selectedCustomer}
                    onChange={setSelectedCustomer}
                    label="Customer (optional)"
                    helperText={selectedCustomer
                      ? (selectedCustomer.is_loyalty_member
                        ? `${selectedCustomer.membership_level} · ${selectedCustomer.points_balance} pts - earns points when the order is Delivered`
                        : 'Not a loyalty member - purchase is recorded on the customer profile')
                      : 'Leave empty for a walk-in sale. Search by name, phone or Customer ID.'}
                  />
                </Grid>
                <Grid size={{ xs: 12, md: 4 }}>
                  <FormControl fullWidth>
                    <InputLabel>Branch</InputLabel>
                    <Select
                      value={formData.branch_id}
                      label="Branch"
                      name="branch_id"
                      onChange={handleInputChange}
                    >
                      <MenuItem value="">Not specified</MenuItem>
                      {options.branches.map(branch => (
                        <MenuItem key={branch.id} value={branch.id}>{branch.name}</MenuItem>
                      ))}
                    </Select>
                  </FormControl>
                </Grid>

                {/* Order Items */}
                <Grid item xs={12}>
                  <Typography variant="h6" gutterBottom>Order Items</Typography>
                  {orderItems.map((item, index) => (
                    <Paper key={index} sx={{ p: 2, mb: 2 }}>
                      <Grid container spacing={2} alignItems="center">
                        <Grid size={{ xs: 12, md: 6 }}>
                          <FormControl fullWidth required>
                            <InputLabel>Product</InputLabel>
                            <Select
                              value={item.product_id}
                              label="Product"
                              onChange={(e) => handleOrderItemChange(index, 'product_id', e.target.value)}
                            >
                              <MenuItem value="">Select Product</MenuItem>
                              {products.map(product => (
                                <MenuItem key={product.id} value={product.id}>
                                  {product.product_name} - {formatCurrency(product.unit_price)} (Stock: {product.total_stock || 0})
                                </MenuItem>
                              ))}
                            </Select>
                          </FormControl>
                        </Grid>
                        <Grid item xs={12} md={4}>
                          <TextField
                            fullWidth
                            label="Quantity"
                            type="number"
                            value={item.quantity}
                            onChange={(e) => handleOrderItemChange(index, 'quantity', e.target.value)}
                            inputProps={{ min: 1 }}
                          />
                        </Grid>
                        <Grid item xs={12} md={2}>
                          {orderItems.length > 1 && (
                            <IconButton color="error" onClick={() => removeOrderItem(index)}>
                              <DeleteIcon />
                            </IconButton>
                          )}
                        </Grid>
                      </Grid>
                    </Paper>
                  ))}
                  <Button onClick={addOrderItem} startIcon={<AddIcon />}>
                    Add Product
                  </Button>
                </Grid>

                {/* Payment Method */}
                <Grid size={{ xs: 12, md: 6 }}>
                  <FormControl fullWidth required>
                    <InputLabel>Payment Method</InputLabel>
                    <Select
                      value={formData.payment_method}
                      label="Payment Method"
                      name="payment_method"
                      onChange={handleInputChange}
                    >
                      <MenuItem value="Cash">Cash</MenuItem>
                      <MenuItem value="Credit Card">Credit Card</MenuItem>
                      <MenuItem value="Bank Transfer">Bank Transfer</MenuItem>
                      <MenuItem value="Check">Check</MenuItem>
                    </Select>
                  </FormControl>
                </Grid>

                {/* Order Summary */}
                <Grid size={{ xs: 12, md: 6 }}>
                  <Card variant="outlined">
                    <CardContent>
                      <Typography variant="h6" gutterBottom>Order Summary</Typography>
                      <Box sx={{ display: 'flex', justifyContent: 'space-between', mb: 1 }}>
                        <Typography>Subtotal:</Typography>
                        <Typography>{formatCurrency(calculateTotal())}</Typography>
                      </Box>
                      <Box sx={{ display: 'flex', justifyContent: 'space-between', mb: 1 }}>
                        <Typography>Tax (0%):</Typography>
                        <Typography>{formatCurrency(0)}</Typography>
                      </Box>
                      <Box sx={{ display: 'flex', justifyContent: 'space-between', mb: 2 }}>
                        <Typography>Shipping:</Typography>
                        <Typography>{formatCurrency(0)}</Typography>
                      </Box>
                      <Divider sx={{ my: 1 }} />
                      <Box sx={{ display: 'flex', justifyContent: 'space-between' }}>
                        <Typography variant="h6">Total:</Typography>
                        <Typography variant="h6" color="primary">
                          {formatCurrency(calculateTotal())}
                        </Typography>
                      </Box>
                    </CardContent>
                  </Card>
                </Grid>
              </Grid>
            </Box>
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setOpenDialog(false)}>Cancel</Button>
            <Button
              onClick={handleCreateOrder}
              variant="contained"
              disabled={orderItems.every(item => !item.product_id)}
              sx={{ backgroundColor: '#1a237e' }}
            >
              Create Order
            </Button>
          </DialogActions>
        </Dialog>

        {/* View Order Details Dialog */}
        <Dialog open={viewDialog} onClose={() => setViewDialog(false)} maxWidth="md" fullWidth>
          {selectedOrder && (
            <>
              <DialogTitle>
                Order {selectedOrder.transaction_number || `#${selectedOrder.id}`} Details
              </DialogTitle>
              <DialogContent>
                <Box sx={{ pt: 2 }}>
                  {/* Order Status Stepper */}
                  <Box sx={{ mb: 4 }}>
                    <Stepper activeStep={statusSteps.indexOf(selectedOrder.order_status)} alternativeLabel>
                      {statusSteps.map((label) => (
                        <Step key={label}>
                          <StepLabel>{label}</StepLabel>
                        </Step>
                      ))}
                    </Stepper>
                  </Box>

                  <Grid container spacing={3}>
                    {/* Order Info */}
                    <Grid size={{ xs: 12, md: 6 }}>
                      <Card variant="outlined">
                        <CardContent>
                          <Typography variant="h6" gutterBottom>Order Information</Typography>
                          <Grid container spacing={2}>
                            <Grid item xs={6}>
                              <Typography variant="body2" color="textSecondary">Order ID:</Typography>
                              <Typography>#{selectedOrder.id}</Typography>
                            </Grid>
                            <Grid item xs={6}>
                              <Typography variant="body2" color="textSecondary">Date:</Typography>
                              <Typography>
                                {new Date(selectedOrder.order_date).toLocaleString()}
                              </Typography>
                            </Grid>
                            <Grid item xs={6}>
                              <Typography variant="body2" color="textSecondary">Status:</Typography>
                              <Chip
                                label={selectedOrder.order_status}
                                color={getStatusColor(selectedOrder.order_status)}
                                size="small"
                              />
                            </Grid>
                            <Grid item xs={6}>
                              <Typography variant="body2" color="textSecondary">Payment:</Typography>
                              <Typography>{selectedOrder.payment_method}</Typography>
                            </Grid>
                          </Grid>
                        </CardContent>
                      </Card>
                    </Grid>

                    {/* Customer Info */}
                    <Grid size={{ xs: 12, md: 6 }}>
                      <Card variant="outlined">
                        <CardContent>
                          <Typography variant="h6" gutterBottom>Customer Information</Typography>
                          {!selectedOrder.customer && (
                            <Typography color="textSecondary">Walk-in sale (no customer account)</Typography>
                          )}
                          {selectedOrder.customer && (
                            <Grid container spacing={2}>
                              <Grid item xs={12}>
                                <Typography variant="body2" color="textSecondary">Name:</Typography>
                                <Typography>{selectedOrder.customer.full_name} ({selectedOrder.customer.customer_code})</Typography>
                              </Grid>
                              <Grid size={{ xs: 12, md: 6 }}>
                                <Typography variant="body2" color="textSecondary">Phone:</Typography>
                                <Typography>{selectedOrder.customer.phone}</Typography>
                              </Grid>
                              <Grid size={{ xs: 12, md: 6 }}>
                                <Typography variant="body2" color="textSecondary">Email:</Typography>
                                <Typography>{selectedOrder.customer.email || 'N/A'}</Typography>
                              </Grid>
                            </Grid>
                          )}
                        </CardContent>
                      </Card>
                    </Grid>

                    {/* Order Items */}
                    <Grid item xs={12}>
                      <Card variant="outlined">
                        <CardContent>
                          <Typography variant="h6" gutterBottom>Order Items</Typography>
                          <Table size="small">
                            <TableHead>
                              <TableRow>
                                <TableCell>Product</TableCell>
                                <TableCell align="right">Price</TableCell>
                                <TableCell align="right">Quantity</TableCell>
                                <TableCell align="right">Subtotal</TableCell>
                              </TableRow>
                            </TableHead>
                            <TableBody>
                              {selectedOrder.order_items?.map((item) => (
                                <TableRow key={item.id}>
                                  <TableCell>{item.product?.product_name}</TableCell>
                                  <TableCell align="right">{formatCurrency(item.unit_price)}</TableCell>
                                  <TableCell align="right">{item.quantity}</TableCell>
                                  <TableCell align="right">{formatCurrency(item.subtotal)}</TableCell>
                                </TableRow>
                              ))}
                              <TableRow>
                                <TableCell colSpan={3} align="right">
                                  <Typography variant="subtitle1">Total:</Typography>
                                </TableCell>
                                <TableCell align="right">
                                  <Typography variant="subtitle1" color="primary">
                                    {formatCurrency(selectedOrder.total_amount)}
                                  </Typography>
                                </TableCell>
                              </TableRow>
                            </TableBody>
                          </Table>
                        </CardContent>
                      </Card>
                    </Grid>
                  </Grid>
                </Box>
              </DialogContent>
              <DialogActions>
                <Button onClick={() => setViewDialog(false)}>Close</Button>
              </DialogActions>
            </>
          )}
        </Dialog>

        {/* Snackbar for notifications */}
        <Snackbar
          open={snackbar.open}
          autoHideDuration={6000}
          onClose={() => setSnackbar({ ...snackbar, open: false })}
        >
          <Alert
            onClose={() => setSnackbar({ ...snackbar, open: false })}
            severity={snackbar.severity}
            sx={{ width: '100%' }}
          >
            {snackbar.message}
          </Alert>
        </Snackbar>
      </Box>
    </LocalizationProvider>
  );
};

export default Orders;
