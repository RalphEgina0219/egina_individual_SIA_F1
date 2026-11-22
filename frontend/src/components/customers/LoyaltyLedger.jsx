import React, { useCallback, useEffect, useState } from 'react';
import {
  Alert,
  Box,
  Chip,
  Grid,
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
import { Search } from '@mui/icons-material';
import { Link as RouterLink } from 'react-router-dom';
import { loyaltyService } from '../../services/api';
import { useNotify } from '../../context/NotifyContext';
import { apiError, cleanParams, formatDateTime, formatNumber } from '../../utils/helpers';
import { ConfirmDialog, TableStateRow } from '../common/UI';

import { LEDGER_TYPES } from '../../utils/constants';

/**
 * Manual points adjustment (Admin / Manager). The reason is stored on the
 * ledger entry and in the customer account log.
 */
export const AdjustPointsDialog = ({ customer, onClose, onDone }) => {
  const notify = useNotify();
  const [points, setPoints] = useState('');
  const [direction, setDirection] = useState('credit');
  const [reason, setReason] = useState('');
  const [saving, setSaving] = useState(false);

  const amount = parseInt(points, 10);
  const valid = amount > 0 && reason.trim().length >= 5;

  const submit = async () => {
    setSaving(true);
    try {
      const res = await loyaltyService.adjust(customer.id, { points: direction === 'credit' ? amount : -amount, reason });
      notify(`${res.message}. New balance: ${res.data.balance_after} points`);
      onDone?.();
      onClose();
    } catch (error) {
      notify(apiError(error).message, 'error');
    } finally {
      setSaving(false);
    }
  };

  return (
    <ConfirmDialog
      open
      title={`Adjust points - ${customer.full_name}`}
      message="Authorized adjustment of the loyalty balance. The adjustment and reason are recorded in the points history and account log."
      confirmText="Post Adjustment"
      loading={saving}
      disabled={!valid}
      onConfirm={submit}
      onClose={onClose}
    >
      <Grid container spacing={2}>
        <Grid size={{ xs: 12, sm: 5 }}>
          <TextField select fullWidth label="Type" value={direction} onChange={(e) => setDirection(e.target.value)}>
            <MenuItem value="credit">Credit (add)</MenuItem>
            <MenuItem value="debit">Debit (deduct)</MenuItem>
          </TextField>
        </Grid>
        <Grid size={{ xs: 12, sm: 7 }}>
          <TextField fullWidth type="number" label="Points" value={points} onChange={(e) => setPoints(e.target.value)} slotProps={{ htmlInput: { min: 1 } }} />
        </Grid>
        <Grid size={12}>
          <TextField fullWidth multiline minRows={2} label="Reason (min. 5 characters)" value={reason} onChange={(e) => setReason(e.target.value)} />
        </Grid>
      </Grid>
    </ConfirmDialog>
  );
};

const LoyaltyLedger = ({ customerId, refreshKey = 0 }) => {
  const notify = useNotify();
  const [filters, setFilters] = useState({ search: '', type: '', start_date: '', end_date: '' });
  const [page, setPage] = useState(0);
  const [rowsPerPage, setRowsPerPage] = useState(15);
  const [rows, setRows] = useState({ data: [], total: 0 });
  const [totals, setTotals] = useState(null);
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const params = cleanParams({ ...filters, page: page + 1, per_page: rowsPerPage });
      if (customerId) {
        const res = await loyaltyService.customer(customerId, params);
        setRows(res.data.transactions);
      } else {
        const res = await loyaltyService.transactions(params);
        setRows(res.data.transactions);
        setTotals(res.data.totals);
      }
    } catch (error) {
      notify(apiError(error, 'Failed to load points history').message, 'error');
    } finally {
      setLoading(false);
    }
  }, [customerId, filters, page, rowsPerPage, notify]);

  useEffect(() => {
    load();
  }, [load, refreshKey]);

  const setFilter = (name, value) => {
    setFilters((prev) => ({ ...prev, [name]: value }));
    setPage(0);
  };

  const columns = customerId ? 6 : 7;

  return (
    <Box>
      {totals && (
        <Grid container spacing={2} sx={{ mb: 2 }}>
          {Object.entries(LEDGER_TYPES).map(([type, meta]) => (
            <Grid key={type} size={{ xs: 6, sm: 4, md: 2 }}>
              <Paper sx={{ p: 1.5 }}>
                <Typography variant="caption" color="text.secondary">{meta.label}</Typography>
                <Typography variant="h6">{formatNumber(Math.abs(totals[type]?.points || 0))}</Typography>
                <Typography variant="caption" color="text.secondary">{totals[type]?.count || 0} entries</Typography>
              </Paper>
            </Grid>
          ))}
        </Grid>
      )}

      <Paper sx={{ p: 2, mb: 2 }}>
        <Grid container spacing={2}>
          {!customerId && (
            <Grid size={{ xs: 12, md: 4 }}>
              <TextField
                fullWidth size="small" placeholder="Customer or description" value={filters.search}
                onChange={(e) => setFilter('search', e.target.value)}
                slotProps={{ input: { startAdornment: <Search sx={{ mr: 1, color: 'text.secondary' }} /> } }}
              />
            </Grid>
          )}
          <Grid size={{ xs: 12, sm: 4, md: customerId ? 4 : 2 }}>
            <TextField select fullWidth size="small" label="Type" value={filters.type} onChange={(e) => setFilter('type', e.target.value)}>
              <MenuItem value="">All</MenuItem>
              {Object.entries(LEDGER_TYPES).map(([type, meta]) => <MenuItem key={type} value={type}>{meta.label}</MenuItem>)}
            </TextField>
          </Grid>
          {!customerId && (
            <>
              <Grid size={{ xs: 6, sm: 4, md: 3 }}>
                <TextField fullWidth size="small" type="date" label="From" value={filters.start_date} onChange={(e) => setFilter('start_date', e.target.value)} slotProps={{ inputLabel: { shrink: true } }} />
              </Grid>
              <Grid size={{ xs: 6, sm: 4, md: 3 }}>
                <TextField fullWidth size="small" type="date" label="To" value={filters.end_date} onChange={(e) => setFilter('end_date', e.target.value)} slotProps={{ inputLabel: { shrink: true } }} />
              </Grid>
            </>
          )}
        </Grid>
      </Paper>

      <TableContainer component={Paper}>
        <Table size="small" sx={{ minWidth: 760 }}>
          <TableHead>
            <TableRow sx={{ backgroundColor: '#f5f5f5' }}>
              <TableCell><strong>Date</strong></TableCell>
              {!customerId && <TableCell><strong>Customer</strong></TableCell>}
              <TableCell><strong>Type</strong></TableCell>
              <TableCell><strong>Description</strong></TableCell>
              <TableCell align="right"><strong>Points</strong></TableCell>
              <TableCell align="right"><strong>Balance</strong></TableCell>
              <TableCell><strong>By</strong></TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            <TableStateRow colSpan={columns} loading={loading} empty={!loading && rows.data.length === 0} message="No points transactions recorded" />
            {!loading && rows.data.map((t) => (
              <TableRow key={t.id} hover>
                <TableCell>{formatDateTime(t.created_at)}</TableCell>
                {!customerId && (
                  <TableCell>
                    <RouterLink to={`/customers/${t.customer?.id}`} style={{ color: '#1a237e' }}>
                      {t.customer?.full_name}
                    </RouterLink>
                    <Typography variant="caption" sx={{ display: 'block' }} color="text.secondary">{t.customer?.customer_code}</Typography>
                  </TableCell>
                )}
                <TableCell>
                  <Chip size="small" label={LEDGER_TYPES[t.type]?.label || t.type} color={LEDGER_TYPES[t.type]?.color || 'default'} />
                </TableCell>
                <TableCell>
                  <Typography variant="body2">{t.description}</Typography>
                  {t.reason && <Typography variant="caption" color="text.secondary">Reason: {t.reason}</Typography>}
                </TableCell>
                <TableCell align="right" sx={{ color: t.points >= 0 ? 'success.main' : 'error.main', fontWeight: 600 }}>
                  {t.points > 0 ? `+${t.points}` : t.points}
                </TableCell>
                <TableCell align="right">{formatNumber(t.balance_after)}</TableCell>
                <TableCell>
                  <Typography variant="body2">{t.performed_by?.name || 'System'}</Typography>
                  {t.authorized_by && <Typography variant="caption" color="text.secondary">Authorized: {t.authorized_by.name}</Typography>}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </TableContainer>
      <TablePagination
        component="div"
        rowsPerPageOptions={[15, 30, 50]}
        count={rows.total || 0}
        page={page}
        rowsPerPage={rowsPerPage}
        onPageChange={(_, p) => setPage(p)}
        onRowsPerPageChange={(e) => { setRowsPerPage(parseInt(e.target.value, 10)); setPage(0); }}
      />
      {!customerId && (
        <Alert severity="info" variant="outlined" sx={{ mt: 1 }}>
          Points are posted automatically when an associated member's order is marked Delivered. Entries cannot be edited or deleted; corrections are posted as adjustments.
        </Alert>
      )}
    </Box>
  );
};

export default LoyaltyLedger;
