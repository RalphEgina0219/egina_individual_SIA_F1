import React, { useCallback, useEffect, useState } from 'react';
import {
  Avatar,
  Box,
  Button,
  Grid,
  IconButton,
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
  Tooltip,
  Typography,
} from '@mui/material';
import {
  Add as AddIcon,
  Archive as ArchiveIcon,
  Delete as DeleteIcon,
  Search as SearchIcon,
  Visibility as VisibilityIcon,
} from '@mui/icons-material';
import { useNavigate } from 'react-router-dom';
import { customerService } from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { useNotify } from '../../context/NotifyContext';
import { useOptions } from '../../hooks/useOptions';
import { apiError, cleanParams, formatCurrency, formatDate } from '../../utils/helpers';
import { ConfirmDialog, PageHeader, StatusChip, TableStateRow } from '../../components/common/UI';

const initialFilters = {
  search: '',
  status: '',
  customer_type: '',
  branch_id: '',
  membership_level_id: '',
  loyalty: '',
  inactive_days: '',
  sort: 'newest',
};

const CustomerList = () => {
  const navigate = useNavigate();
  const notify = useNotify();
  const { can } = useAuth();
  const options = useOptions();

  const [filters, setFilters] = useState(initialFilters);
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(0);
  const [rowsPerPage, setRowsPerPage] = useState(10);
  const [data, setData] = useState({ data: [], total: 0 });
  const [loading, setLoading] = useState(true);
  const [confirm, setConfirm] = useState(null);
  const [reason, setReason] = useState('');
  const [working, setWorking] = useState(false);

  // Debounce the search box
  useEffect(() => {
    const timer = setTimeout(() => {
      setFilters((prev) => (prev.search === search ? prev : { ...prev, search }));
      setPage(0);
    }, 350);
    return () => clearTimeout(timer);
  }, [search]);

  const fetchCustomers = useCallback(async () => {
    setLoading(true);
    try {
      const res = await customerService.getAll(cleanParams({ ...filters, page: page + 1, per_page: rowsPerPage }));
      setData(res.data);
    } catch (error) {
      notify(apiError(error, 'Failed to load customers').message, 'error');
    } finally {
      setLoading(false);
    }
  }, [filters, page, rowsPerPage, notify]);

  useEffect(() => {
    fetchCustomers();
  }, [fetchCustomers]);

  const setFilter = (name, value) => {
    setFilters((prev) => ({ ...prev, [name]: value }));
    setPage(0);
  };

  const handleConfirm = async () => {
    setWorking(true);
    try {
      if (confirm.type === 'archive') {
        await customerService.changeStatus(confirm.customer.id, { status: 'Archived', reason });
        notify('Customer archived');
      } else {
        await customerService.delete(confirm.customer.id);
        notify('Customer deleted');
      }
      setConfirm(null);
      setReason('');
      fetchCustomers();
    } catch (error) {
      notify(apiError(error).message, 'error');
    } finally {
      setWorking(false);
    }
  };

  return (
    <Box>
      <PageHeader
        title="All Customers"
        subtitle="Search, filter and manage ShopLink customer accounts"
        actions={can('customers.create') && (
          <Button variant="contained" startIcon={<AddIcon />} onClick={() => navigate('/customers/register')} sx={{ backgroundColor: '#1a237e' }}>
            Register Customer
          </Button>
        )}
      />

      <Paper sx={{ p: 2, mb: 3 }}>
        <Grid container spacing={2}>
          <Grid size={{ xs: 12, md: 4 }}>
            <TextField
              fullWidth
              size="small"
              placeholder="Name, phone, e-mail, Customer ID, membership no."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              slotProps={{ input: { startAdornment: <SearchIcon sx={{ mr: 1, color: 'text.secondary' }} /> } }}
            />
          </Grid>
          <Grid size={{ xs: 6, md: 2 }}>
            <TextField select fullWidth size="small" label="Status" value={filters.status} onChange={(e) => setFilter('status', e.target.value)}>
              <MenuItem value="">All (excl. archived)</MenuItem>
              {options.customer_statuses.map((s) => <MenuItem key={s} value={s}>{s}</MenuItem>)}
            </TextField>
          </Grid>
          <Grid size={{ xs: 6, md: 2 }}>
            <TextField select fullWidth size="small" label="Membership" value={filters.membership_level_id} onChange={(e) => setFilter('membership_level_id', e.target.value)}>
              <MenuItem value="">All levels</MenuItem>
              {options.membership_levels.map((l) => <MenuItem key={l.id} value={l.id}>{l.name}</MenuItem>)}
            </TextField>
          </Grid>
          <Grid size={{ xs: 6, md: 2 }}>
            <TextField select fullWidth size="small" label="Customer Type" value={filters.customer_type} onChange={(e) => setFilter('customer_type', e.target.value)}>
              <MenuItem value="">All types</MenuItem>
              {options.customer_types.map((t) => <MenuItem key={t} value={t}>{t}</MenuItem>)}
            </TextField>
          </Grid>
          <Grid size={{ xs: 6, md: 2 }}>
            <TextField select fullWidth size="small" label="Preferred Branch" value={filters.branch_id} onChange={(e) => setFilter('branch_id', e.target.value)}>
              <MenuItem value="">All branches</MenuItem>
              {options.branches.map((b) => <MenuItem key={b.id} value={b.id}>{b.name}</MenuItem>)}
            </TextField>
          </Grid>
          <Grid size={{ xs: 6, md: 2 }}>
            <TextField select fullWidth size="small" label="Loyalty" value={filters.loyalty} onChange={(e) => setFilter('loyalty', e.target.value)}>
              <MenuItem value="">All</MenuItem>
              <MenuItem value="enrolled">Enrolled</MenuItem>
              <MenuItem value="not_enrolled">Not enrolled</MenuItem>
            </TextField>
          </Grid>
          <Grid size={{ xs: 6, md: 2 }}>
            <TextField select fullWidth size="small" label="No purchase in" value={filters.inactive_days} onChange={(e) => setFilter('inactive_days', e.target.value)}>
              <MenuItem value="">Any activity</MenuItem>
              <MenuItem value={30}>30 days</MenuItem>
              <MenuItem value={60}>60 days</MenuItem>
              <MenuItem value={90}>90 days</MenuItem>
              <MenuItem value={180}>180 days</MenuItem>
            </TextField>
          </Grid>
          <Grid size={{ xs: 6, md: 2 }}>
            <TextField select fullWidth size="small" label="Sort by" value={filters.sort} onChange={(e) => setFilter('sort', e.target.value)}>
              <MenuItem value="newest">Newest</MenuItem>
              <MenuItem value="oldest">Oldest</MenuItem>
              <MenuItem value="name">Name</MenuItem>
              <MenuItem value="last_purchase">Last purchase</MenuItem>
              <MenuItem value="total_spent">Total spent</MenuItem>
            </TextField>
          </Grid>
          <Grid size={{ xs: 6, md: 2 }}>
            <Button fullWidth variant="outlined" onClick={() => { setFilters(initialFilters); setSearch(''); setPage(0); }}>
              Clear
            </Button>
          </Grid>
        </Grid>
      </Paper>

      <TableContainer component={Paper}>
        <Table size="small" sx={{ minWidth: 900 }}>
          <TableHead>
            <TableRow sx={{ backgroundColor: '#f5f5f5' }}>
              <TableCell><strong>Customer</strong></TableCell>
              <TableCell><strong>Contact</strong></TableCell>
              <TableCell><strong>Status</strong></TableCell>
              <TableCell><strong>Membership</strong></TableCell>
              <TableCell><strong>Branch</strong></TableCell>
              <TableCell align="right"><strong>Total Spent</strong></TableCell>
              <TableCell><strong>Last Purchase</strong></TableCell>
              <TableCell align="center"><strong>Actions</strong></TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            <TableStateRow colSpan={8} loading={loading} empty={!loading && data.data.length === 0} message="No customers match the current filters" />
            {!loading && data.data.map((customer) => (
              <TableRow key={customer.id} hover sx={{ cursor: 'pointer' }} onClick={() => navigate(`/customers/${customer.id}`)}>
                <TableCell>
                  <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5 }}>
                    <Avatar sx={{ bgcolor: '#1a237e', width: 34, height: 34 }}>{customer.full_name.charAt(0)}</Avatar>
                    <Box>
                      <Typography variant="subtitle2">{customer.full_name}</Typography>
                      <Typography variant="caption" color="text.secondary">
                        {customer.customer_code} · {customer.customer_type}
                      </Typography>
                    </Box>
                  </Box>
                </TableCell>
                <TableCell>
                  <Typography variant="body2">{customer.phone}</Typography>
                  <Typography variant="caption" color="text.secondary">{customer.email || 'No e-mail'}</Typography>
                </TableCell>
                <TableCell><StatusChip status={customer.status} /></TableCell>
                <TableCell>
                  <Typography variant="body2">{customer.membership?.level?.name}</Typography>
                  {customer.membership?.is_loyalty_member && (
                    <Typography variant="caption" color="text.secondary">{customer.membership.points_balance} pts</Typography>
                  )}
                </TableCell>
                <TableCell>{customer.preferred_branch?.name || '-'}</TableCell>
                <TableCell align="right">{formatCurrency(customer.total_spent || 0)}</TableCell>
                <TableCell>{customer.last_purchase_at ? formatDate(customer.last_purchase_at) : 'Never'}</TableCell>
                <TableCell align="center" onClick={(e) => e.stopPropagation()}>
                  <Box sx={{ display: 'flex', justifyContent: 'center' }}>
                    <Tooltip title="View profile">
                      <IconButton size="small" color="primary" onClick={() => navigate(`/customers/${customer.id}`)}>
                        <VisibilityIcon fontSize="small" />
                      </IconButton>
                    </Tooltip>
                    {can('customers.status') && customer.status !== 'Archived' && (
                      <Tooltip title="Archive">
                        <IconButton size="small" color="warning" onClick={() => setConfirm({ type: 'archive', customer })}>
                          <ArchiveIcon fontSize="small" />
                        </IconButton>
                      </Tooltip>
                    )}
                    {can('customers.delete') && (
                      <Tooltip title="Delete (only accounts without history)">
                        <IconButton size="small" color="error" onClick={() => setConfirm({ type: 'delete', customer })}>
                          <DeleteIcon fontSize="small" />
                        </IconButton>
                      </Tooltip>
                    )}
                  </Box>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </TableContainer>
      <TablePagination
        component="div"
        rowsPerPageOptions={[10, 25, 50]}
        count={data.total || 0}
        page={page}
        rowsPerPage={rowsPerPage}
        onPageChange={(_, p) => setPage(p)}
        onRowsPerPageChange={(e) => { setRowsPerPage(parseInt(e.target.value, 10)); setPage(0); }}
      />

      <ConfirmDialog
        open={Boolean(confirm)}
        title={confirm?.type === 'archive' ? 'Archive customer account' : 'Delete customer'}
        message={confirm?.type === 'archive'
          ? `Archive ${confirm?.customer.full_name} (${confirm?.customer.customer_code})? The record and its history are kept but hidden from active lists.`
          : `Permanently delete ${confirm?.customer.full_name}? Only accounts without purchase, loyalty or feedback history can be deleted; use Archive otherwise.`}
        confirmText={confirm?.type === 'archive' ? 'Archive' : 'Delete'}
        color={confirm?.type === 'archive' ? 'warning' : 'error'}
        loading={working}
        disabled={confirm?.type === 'archive' && !reason.trim()}
        onConfirm={handleConfirm}
        onClose={() => { setConfirm(null); setReason(''); }}
      >
        {confirm?.type === 'archive' && (
          <TextField fullWidth label="Reason" required value={reason} onChange={(e) => setReason(e.target.value)} />
        )}
      </ConfirmDialog>
    </Box>
  );
};

export default CustomerList;
