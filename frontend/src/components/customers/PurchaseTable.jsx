import React, { useCallback, useEffect, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Chip,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Grid,
  List,
  ListItem,
  ListItemIcon,
  ListItemText,
  MenuItem,
  Paper,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TablePagination,
  TableRow,
  TextField,
  Typography,
} from '@mui/material';
import { CheckCircle, ErrorOutline, FactCheck, Link as LinkIcon, Search, Sync } from '@mui/icons-material';
import { customerService, loyaltyService, purchaseHistoryService } from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { useNotify } from '../../context/NotifyContext';
import { useOptions } from '../../hooks/useOptions';
import { apiError, cleanParams, formatCurrency, formatDateTime } from '../../utils/helpers';
import { InfoRow, StatusChip, TableStateRow } from '../common/UI';
import CustomerPicker from '../common/CustomerPicker';

const ORDER_STATUSES = ['Pending', 'Processing', 'Shipped', 'Delivered', 'Cancelled'];

/**
 * Transaction details with verification, customer association and points sync.
 */
export const TransactionDialog = ({ order, onClose, onChanged }) => {
  const { can } = useAuth();
  const notify = useNotify();
  const [verification, setVerification] = useState(null);
  const [verifying, setVerifying] = useState(false);
  const [associateTo, setAssociateTo] = useState(null);
  const [reason, setReason] = useState('');
  const [working, setWorking] = useState(false);

  const runVerify = async () => {
    setVerifying(true);
    try {
      const res = await purchaseHistoryService.verify(order.id);
      setVerification(res.data);
    } catch (error) {
      notify(apiError(error).message, 'error');
    } finally {
      setVerifying(false);
    }
  };

  const runAction = async (action) => {
    setWorking(true);
    try {
      const res = await action();
      notify(res.message);
      setVerification(null);
      onChanged?.();
      onClose();
    } catch (error) {
      notify(apiError(error).message, 'error');
    } finally {
      setWorking(false);
    }
  };

  return (
    <Dialog open onClose={onClose} maxWidth="md" fullWidth>
      <DialogTitle>Transaction {order.transaction_number}</DialogTitle>
      <DialogContent dividers>
        <Grid container spacing={2}>
          <Grid size={{ xs: 6, md: 3 }}><InfoRow label="Date" value={formatDateTime(order.order_date)} /></Grid>
          <Grid size={{ xs: 6, md: 3 }}><InfoRow label="Status" value={<StatusChip status={order.order_status} />} /></Grid>
          <Grid size={{ xs: 6, md: 3 }}><InfoRow label="Branch" value={order.branch?.name || 'Not recorded'} /></Grid>
          <Grid size={{ xs: 6, md: 3 }}><InfoRow label="Payment" value={order.payment_method} /></Grid>
          <Grid size={{ xs: 6, md: 3 }}>
            <InfoRow label="Customer" value={order.customer ? `${order.customer.full_name} (${order.customer.customer_code})` : 'Walk-in'} />
          </Grid>
          <Grid size={{ xs: 6, md: 3 }}><InfoRow label="Cashier" value={order.cashier?.name} /></Grid>
          <Grid size={{ xs: 6, md: 3 }}><InfoRow label="Points Earned" value={order.points_earned} /></Grid>
          <Grid size={{ xs: 6, md: 3 }}><InfoRow label="Total" value={<strong>{formatCurrency(order.total_amount)}</strong>} /></Grid>
        </Grid>

        <TableContainer sx={{ mt: 1 }}>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Product</TableCell>
                <TableCell>SKU</TableCell>
                <TableCell align="right">Qty</TableCell>
                <TableCell align="right">Unit Price</TableCell>
                <TableCell align="right">Subtotal</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {order.items.map((item) => (
                <TableRow key={item.id}>
                  <TableCell>{item.product_name}</TableCell>
                  <TableCell>{item.sku}</TableCell>
                  <TableCell align="right">{item.quantity}</TableCell>
                  <TableCell align="right">{formatCurrency(item.unit_price)}</TableCell>
                  <TableCell align="right">{formatCurrency(item.subtotal)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TableContainer>

        {verification && (
          <Box sx={{ mt: 2 }}>
            <Alert severity={verification.all_passed ? 'success' : 'warning'} sx={{ mb: 1 }}>
              {verification.all_passed ? 'All verification checks passed.' : 'Discrepancies found - review the checks below.'}
            </Alert>
            <List dense>
              {verification.checks.map((check) => (
                <ListItem key={check.check}>
                  <ListItemIcon>{check.passed ? <CheckCircle color="success" /> : <ErrorOutline color="warning" />}</ListItemIcon>
                  <ListItemText primary={check.check} secondary={check.detail} />
                </ListItem>
              ))}
            </List>
          </Box>
        )}

        {!order.customer && can(['customers.purchases.investigate', 'orders.manage']) && (
          <Box sx={{ mt: 2 }}>
            <Typography variant="subtitle2" gutterBottom>Associate with a customer account</Typography>
            <Grid container spacing={2}>
              <Grid size={{ xs: 12, md: 6 }}>
                <CustomerPicker value={associateTo} onChange={setAssociateTo} size="small" />
              </Grid>
              <Grid size={{ xs: 12, md: 6 }}>
                <TextField fullWidth size="small" label="Reason" value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. customer forgot membership card" />
              </Grid>
            </Grid>
          </Box>
        )}
      </DialogContent>
      <DialogActions sx={{ flexWrap: 'wrap', gap: 1 }}>
        {can('customers.purchases.investigate') && (
          <Button startIcon={verifying ? <CircularProgress size={16} /> : <FactCheck />} onClick={runVerify} disabled={verifying}>
            Verify Transaction
          </Button>
        )}
        {verification?.can_sync_points && can('loyalty.sync') && (
          <Button startIcon={<Sync />} disabled={working} onClick={() => runAction(() => loyaltyService.syncOrder(order.id))}>
            Post Missing Points
          </Button>
        )}
        {!order.customer && can(['customers.purchases.investigate', 'orders.manage']) && (
          <Button
            startIcon={<LinkIcon />}
            disabled={working || !associateTo || !reason.trim()}
            onClick={() => runAction(() => purchaseHistoryService.associate(order.id, { customer_id: associateTo.id, reason }))}
          >
            Associate Customer
          </Button>
        )}
        <Button onClick={onClose}>Close</Button>
      </DialogActions>
    </Dialog>
  );
};

const PurchaseTable = ({ customerId }) => {
  const notify = useNotify();
  const options = useOptions();
  const [filters, setFilters] = useState({ search: '', status: '', branch_id: '', start_date: '', end_date: '', linked: '' });
  const [page, setPage] = useState(0);
  const [rowsPerPage, setRowsPerPage] = useState(10);
  const [rows, setRows] = useState({ data: [], total: 0 });
  const [summary, setSummary] = useState(null);
  const [loading, setLoading] = useState(true);
  const [selected, setSelected] = useState(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const params = cleanParams({ ...filters, page: page + 1, per_page: rowsPerPage });
      if (customerId) {
        const res = await customerService.purchases(customerId, params);
        setRows(res.data.orders);
        setSummary(res.data.summary);
      } else {
        const res = await purchaseHistoryService.getAll(params);
        setRows(res.data);
      }
    } catch (error) {
      notify(apiError(error, 'Failed to load purchase history').message, 'error');
    } finally {
      setLoading(false);
    }
  }, [customerId, filters, page, rowsPerPage, notify]);

  useEffect(() => {
    load();
  }, [load]);

  const setFilter = (name, value) => {
    setFilters((prev) => ({ ...prev, [name]: value }));
    setPage(0);
  };

  const columns = customerId ? 8 : 9;

  return (
    <Box>
      {summary && (
        <Grid container spacing={2} sx={{ mb: 2 }}>
          <Grid size={{ xs: 6, md: 3 }}><Paper sx={{ p: 2 }}><InfoRow label="Transactions" value={summary.total_transactions} /></Paper></Grid>
          <Grid size={{ xs: 6, md: 3 }}><Paper sx={{ p: 2 }}><InfoRow label="Total Spent" value={formatCurrency(summary.total_spent)} /></Paper></Grid>
          <Grid size={{ xs: 6, md: 3 }}><Paper sx={{ p: 2 }}><InfoRow label="Average Transaction" value={formatCurrency(summary.average_transaction)} /></Paper></Grid>
          <Grid size={{ xs: 6, md: 3 }}>
            <Paper sx={{ p: 2 }}>
              <InfoRow label="Most Purchased" value={summary.top_products?.length ? summary.top_products.map((p) => `${p.product_name} (${p.total_quantity})`).slice(0, 2).join(', ') : '-'} />
            </Paper>
          </Grid>
        </Grid>
      )}

      <Paper sx={{ p: 2, mb: 2 }}>
        <Grid container spacing={2}>
          <Grid size={{ xs: 12, md: customerId ? 4 : 3 }}>
            <TextField
              fullWidth size="small" placeholder={customerId ? 'Transaction no. or product' : 'Transaction no., customer or product'}
              value={filters.search} onChange={(e) => setFilter('search', e.target.value)}
              slotProps={{ input: { startAdornment: <Search sx={{ mr: 1, color: 'text.secondary' }} /> } }}
            />
          </Grid>
          <Grid size={{ xs: 6, md: 2 }}>
            <TextField select fullWidth size="small" label="Status" value={filters.status} onChange={(e) => setFilter('status', e.target.value)}>
              <MenuItem value="">All</MenuItem>
              {ORDER_STATUSES.map((s) => <MenuItem key={s} value={s}>{s}</MenuItem>)}
            </TextField>
          </Grid>
          <Grid size={{ xs: 6, md: 2 }}>
            <TextField select fullWidth size="small" label="Branch" value={filters.branch_id} onChange={(e) => setFilter('branch_id', e.target.value)}>
              <MenuItem value="">All</MenuItem>
              {options.branches.map((b) => <MenuItem key={b.id} value={b.id}>{b.name}</MenuItem>)}
            </TextField>
          </Grid>
          <Grid size={{ xs: 6, md: 2 }}>
            <TextField fullWidth size="small" type="date" label="From" value={filters.start_date} onChange={(e) => setFilter('start_date', e.target.value)} slotProps={{ inputLabel: { shrink: true } }} />
          </Grid>
          <Grid size={{ xs: 6, md: 2 }}>
            <TextField fullWidth size="small" type="date" label="To" value={filters.end_date} onChange={(e) => setFilter('end_date', e.target.value)} slotProps={{ inputLabel: { shrink: true } }} />
          </Grid>
          {!customerId && (
            <Grid size={{ xs: 12, md: 1 }}>
              <TextField select fullWidth size="small" label="Type" value={filters.linked} onChange={(e) => setFilter('linked', e.target.value)}>
                <MenuItem value="">All</MenuItem>
                <MenuItem value="customer">Members</MenuItem>
                <MenuItem value="walk_in">Walk-in</MenuItem>
              </TextField>
            </Grid>
          )}
        </Grid>
      </Paper>

      <TableContainer component={Paper}>
        <Table size="small" sx={{ minWidth: 820 }}>
          <TableHead>
            <TableRow sx={{ backgroundColor: '#f5f5f5' }}>
              <TableCell><strong>Transaction No.</strong></TableCell>
              <TableCell><strong>Date</strong></TableCell>
              {!customerId && <TableCell><strong>Customer</strong></TableCell>}
              <TableCell><strong>Branch</strong></TableCell>
              <TableCell align="right"><strong>Items</strong></TableCell>
              <TableCell align="right"><strong>Total</strong></TableCell>
              <TableCell><strong>Payment</strong></TableCell>
              <TableCell><strong>Status</strong></TableCell>
              <TableCell align="right"><strong>Points</strong></TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            <TableStateRow colSpan={columns} loading={loading} empty={!loading && rows.data.length === 0} message="No transactions found" />
            {!loading && rows.data.map((order) => (
              <TableRow key={order.id} hover sx={{ cursor: 'pointer' }} onClick={() => setSelected(order)}>
                <TableCell><Typography variant="body2" color="primary">{order.transaction_number}</Typography></TableCell>
                <TableCell>{formatDateTime(order.order_date)}</TableCell>
                {!customerId && (
                  <TableCell>
                    {order.customer ? `${order.customer.full_name} (${order.customer.customer_code})` : <Chip size="small" label="Walk-in" variant="outlined" />}
                  </TableCell>
                )}
                <TableCell>{order.branch?.name || '-'}</TableCell>
                <TableCell align="right">{order.items_count}</TableCell>
                <TableCell align="right">{formatCurrency(order.total_amount)}</TableCell>
                <TableCell>{order.payment_method}</TableCell>
                <TableCell><StatusChip status={order.order_status} /></TableCell>
                <TableCell align="right">{order.points_earned || '-'}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </TableContainer>
      <TablePagination
        component="div"
        rowsPerPageOptions={[10, 25, 50]}
        count={rows.total || 0}
        page={page}
        rowsPerPage={rowsPerPage}
        onPageChange={(_, p) => setPage(p)}
        onRowsPerPageChange={(e) => { setRowsPerPage(parseInt(e.target.value, 10)); setPage(0); }}
      />

      {selected && <TransactionDialog key={selected.id} order={selected} onClose={() => setSelected(null)} onChanged={load} />}
    </Box>
  );
};

export default PurchaseTable;
