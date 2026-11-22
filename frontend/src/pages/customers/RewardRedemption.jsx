import React, { useCallback, useEffect, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Card,
  CardActions,
  CardContent,
  Chip,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControlLabel,
  Grid,
  IconButton,
  MenuItem,
  Paper,
  Switch,
  Tab,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TablePagination,
  TableRow,
  Tabs,
  TextField,
  Tooltip,
  Typography,
} from '@mui/material';
import { Add, CheckCircle, Delete, Edit, Print, Redeem, Search } from '@mui/icons-material';
import { useSearchParams } from 'react-router-dom';
import { customerService, redemptionService, rewardService } from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { useNotify } from '../../context/NotifyContext';
import { useOptions } from '../../hooks/useOptions';
import { apiError, cleanParams, formatDate, formatDateTime, formatNumber, newIdempotencyKey } from '../../utils/helpers';
import { ConfirmDialog, InfoRow, PageHeader, StatusChip, TableStateRow } from '../../components/common/UI';
import CustomerPicker from '../../components/common/CustomerPicker';

// ---------------------------------------------------------------------------
// Process redemption (workflow steps 1-9)
// ---------------------------------------------------------------------------
const ProcessRedemption = ({ initialCustomerId, onRedeemed }) => {
  const notify = useNotify();
  const options = useOptions();
  const [customer, setCustomer] = useState(null);
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [selected, setSelected] = useState(null);
  const [quantity, setQuantity] = useState(1);
  const [branchId, setBranchId] = useState('');
  const [notes, setNotes] = useState('');
  const [check, setCheck] = useState(null);
  const [requestKey, setRequestKey] = useState(null);
  const [submitting, setSubmitting] = useState(false);
  const [confirmation, setConfirmation] = useState(null);

  const loadRewards = useCallback(async (customerId) => {
    setLoading(true);
    try {
      const res = await customerService.rewards(customerId);
      setData(res.data);
      return res.data;
    } catch (error) {
      notify(apiError(error, 'Failed to load customer rewards').message, 'error');
      return null;
    } finally {
      setLoading(false);
    }
  }, [notify]);

  // Preselect the customer when opened from a profile (?customer=ID)
  useEffect(() => {
    if (!initialCustomerId) return;
    loadRewards(initialCustomerId).then((d) => {
      if (d) setCustomer({ ...d.customer, can_transact: true, phone: '' });
    });
  }, [initialCustomerId, loadRewards]);

  const selectCustomer = (value) => {
    setCustomer(value);
    setData(null);
    setConfirmation(null);
    if (value) loadRewards(value.id);
  };

  const openConfirm = async (reward) => {
    setSelected(reward);
    setQuantity(1);
    setNotes('');
    setCheck({ eligible: reward.eligible, reason: reward.ineligible_reason, points_required: reward.points_required, points_balance: data.customer.points_balance });
    setRequestKey(newIdempotencyKey());
  };

  const recheck = async (qty) => {
    setQuantity(qty);
    try {
      const res = await redemptionService.check({ customer_id: customer.id, reward_id: selected.id, quantity: qty });
      setCheck(res.data);
    } catch (error) {
      notify(apiError(error).message, 'error');
    }
  };

  const confirm = async () => {
    setSubmitting(true);
    try {
      const res = await redemptionService.create({
        customer_id: customer.id,
        reward_id: selected.id,
        quantity,
        branch_id: branchId || null,
        notes: notes || null,
        idempotency_key: requestKey,
      });
      setConfirmation(res.data);
      setSelected(null);
      notify(res.message);
      loadRewards(customer.id);
      onRedeemed?.();
    } catch (error) {
      notify(apiError(error, 'Redemption failed').message, 'error');
      recheck(quantity);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Box>
      <Paper sx={{ p: 2, mb: 2 }}>
        <Grid container spacing={2}>
          <Grid size={{ xs: 12, md: 8 }}>
            <CustomerPicker value={customer} onChange={selectCustomer} label="Retrieve customer account" />
          </Grid>
          <Grid size={{ xs: 12, md: 4 }}>
            <TextField select fullWidth label="Branch" value={branchId} onChange={(e) => setBranchId(e.target.value)}>
              <MenuItem value="">Not specified</MenuItem>
              {options.branches.map((b) => <MenuItem key={b.id} value={b.id}>{b.name}</MenuItem>)}
            </TextField>
          </Grid>
        </Grid>
      </Paper>

      {confirmation && (
        <Card sx={{ mb: 2, borderLeft: 4, borderColor: 'success.main' }} className="print-area">
          <CardContent>
            <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, mb: 1 }}>
              <CheckCircle color="success" />
              <Typography variant="h6">Redemption Confirmed</Typography>
              {confirmation.duplicate_request && <Chip size="small" color="warning" label="Already processed - no additional points deducted" />}
            </Box>
            <Grid container spacing={1}>
              <Grid size={{ xs: 6, md: 3 }}><InfoRow label="Redemption No." value={<strong>{confirmation.redemption_number}</strong>} /></Grid>
              <Grid size={{ xs: 6, md: 3 }}><InfoRow label="Customer" value={`${confirmation.customer.full_name} (${confirmation.customer.customer_code})`} /></Grid>
              <Grid size={{ xs: 6, md: 3 }}><InfoRow label="Reward" value={`${confirmation.quantity} x ${confirmation.reward.name}`} /></Grid>
              <Grid size={{ xs: 6, md: 3 }}><InfoRow label="Date" value={formatDateTime(confirmation.confirmed_at)} /></Grid>
              <Grid size={{ xs: 6, md: 3 }}><InfoRow label="Points Used" value={formatNumber(confirmation.points_used)} /></Grid>
              <Grid size={{ xs: 6, md: 3 }}><InfoRow label="Remaining Balance" value={formatNumber(confirmation.points_balance)} /></Grid>
              <Grid size={{ xs: 6, md: 3 }}><InfoRow label="Processed By" value={confirmation.processed_by?.name} /></Grid>
            </Grid>
            <Button size="small" startIcon={<Print />} onClick={() => window.print()}>Print Confirmation</Button>
          </CardContent>
        </Card>
      )}

      {loading && <Box sx={{ textAlign: 'center', py: 4 }}><CircularProgress /></Box>}

      {data && !loading && (
        <>
          <Paper sx={{ p: 2, mb: 2, display: 'flex', gap: 3, flexWrap: 'wrap', alignItems: 'center' }}>
            <InfoRow label="Customer" value={`${data.customer.full_name} (${data.customer.customer_code})`} />
            <InfoRow label="Status" value={<StatusChip status={data.customer.status} />} />
            <InfoRow label="Membership" value={data.customer.membership_level} />
            <InfoRow label="Available Points" value={<Typography variant="h6" component="span">{formatNumber(data.customer.points_balance)}</Typography>} />
          </Paper>
          {!data.customer.is_loyalty_member && (
            <Alert severity="warning" sx={{ mb: 2 }}>This customer is not enrolled in the loyalty program and cannot redeem rewards.</Alert>
          )}
          {data.rewards.length === 0 ? (
            <Alert severity="info">No rewards are currently available.</Alert>
          ) : (
            <Grid container spacing={2}>
              {data.rewards.map((reward) => (
                <Grid key={reward.id} size={{ xs: 12, sm: 6, md: 4 }}>
                  <Card sx={{ height: '100%', display: 'flex', flexDirection: 'column', opacity: reward.eligible ? 1 : 0.7 }}>
                    <CardContent sx={{ flexGrow: 1 }}>
                      <Box sx={{ display: 'flex', justifyContent: 'space-between', gap: 1 }}>
                        <Typography variant="subtitle1" sx={{ fontWeight: 600 }}>{reward.name}</Typography>
                        <Chip size="small" color="primary" label={`${formatNumber(reward.points_required)} pts`} />
                      </Box>
                      <Typography variant="body2" color="text.secondary" sx={{ my: 1 }}>{reward.description}</Typography>
                      <Typography variant="caption" color="text.secondary" component="div">
                        {reward.stock_quantity !== null ? `${reward.stock_quantity} in stock` : 'Unlimited availability'}
                        {reward.max_per_customer ? ` · max ${reward.max_per_customer} per customer` : ''}
                        {reward.min_level ? ` · ${reward.min_level.name}+` : ''}
                        {reward.valid_until ? ` · until ${formatDate(reward.valid_until)}` : ''}
                      </Typography>
                      {!reward.eligible && <Alert severity="warning" sx={{ mt: 1, py: 0 }}>{reward.ineligible_reason}</Alert>}
                    </CardContent>
                    <CardActions>
                      <Button size="small" variant="contained" startIcon={<Redeem />} disabled={!reward.eligible} onClick={() => openConfirm(reward)} sx={{ backgroundColor: '#1a237e' }}>
                        Redeem
                      </Button>
                    </CardActions>
                  </Card>
                </Grid>
              ))}
            </Grid>
          )}
        </>
      )}

      {!customer && !confirmation && (
        <Alert severity="info" variant="outlined">Retrieve the customer account to view the available points and eligible rewards.</Alert>
      )}

      <Dialog open={Boolean(selected)} onClose={submitting ? undefined : () => setSelected(null)} maxWidth="sm" fullWidth>
        <DialogTitle>Confirm Redemption</DialogTitle>
        <DialogContent dividers>
          {selected && check && (
            <>
              <Grid container spacing={2}>
                <Grid size={6}><InfoRow label="Customer" value={customer?.full_name} /></Grid>
                <Grid size={6}><InfoRow label="Reward" value={selected.name} /></Grid>
                <Grid size={6}>
                  <TextField fullWidth size="small" type="number" label="Quantity" value={quantity}
                    onChange={(e) => recheck(Math.max(1, parseInt(e.target.value, 10) || 1))}
                    slotProps={{ htmlInput: { min: 1, max: 100 } }} />
                </Grid>
                <Grid size={6}><InfoRow label="Points Required" value={formatNumber(check.points_required)} /></Grid>
                <Grid size={6}><InfoRow label="Available Points" value={formatNumber(check.points_balance)} /></Grid>
                <Grid size={6}><InfoRow label="Balance After" value={formatNumber(check.points_balance - check.points_required)} /></Grid>
                <Grid size={12}>
                  <TextField fullWidth size="small" label="Notes (optional)" value={notes} onChange={(e) => setNotes(e.target.value)} />
                </Grid>
              </Grid>
              <Alert severity={check.eligible ? 'success' : 'error'} sx={{ mt: 2 }}>
                {check.eligible ? 'Customer is eligible. Points are verified again and deducted on confirmation.' : check.reason}
              </Alert>
            </>
          )}
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setSelected(null)} disabled={submitting}>Cancel</Button>
          <Button variant="contained" onClick={confirm} disabled={submitting || !check?.eligible}
            startIcon={submitting ? <CircularProgress size={16} color="inherit" /> : <CheckCircle />} sx={{ backgroundColor: '#1a237e' }}>
            Confirm Redemption
          </Button>
        </DialogActions>
      </Dialog>
    </Box>
  );
};

// ---------------------------------------------------------------------------
// Redemption history
// ---------------------------------------------------------------------------
const RedemptionHistory = ({ refreshKey }) => {
  const { can } = useAuth();
  const notify = useNotify();
  const [filters, setFilters] = useState({ search: '', status: '', start_date: '', end_date: '' });
  const [page, setPage] = useState(0);
  const [rowsPerPage, setRowsPerPage] = useState(15);
  const [rows, setRows] = useState({ data: [], total: 0 });
  const [loading, setLoading] = useState(true);
  const [cancelling, setCancelling] = useState(null);
  const [reason, setReason] = useState('');
  const [working, setWorking] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await redemptionService.getAll(cleanParams({ ...filters, page: page + 1, per_page: rowsPerPage }));
      setRows(res.data);
    } catch (error) {
      notify(apiError(error, 'Failed to load redemptions').message, 'error');
    } finally {
      setLoading(false);
    }
  }, [filters, page, rowsPerPage, notify]);

  useEffect(() => {
    load();
  }, [load, refreshKey]);

  const setFilter = (name, value) => {
    setFilters((prev) => ({ ...prev, [name]: value }));
    setPage(0);
  };

  const cancel = async () => {
    setWorking(true);
    try {
      const res = await redemptionService.cancel(cancelling.id, { reason });
      notify(res.message);
      setCancelling(null);
      setReason('');
      load();
    } catch (error) {
      notify(apiError(error).message, 'error');
    } finally {
      setWorking(false);
    }
  };

  return (
    <Box>
      <Paper sx={{ p: 2, mb: 2 }}>
        <Grid container spacing={2}>
          <Grid size={{ xs: 12, md: 4 }}>
            <TextField fullWidth size="small" placeholder="Redemption no. or customer" value={filters.search} onChange={(e) => setFilter('search', e.target.value)}
              slotProps={{ input: { startAdornment: <Search sx={{ mr: 1, color: 'text.secondary' }} /> } }} />
          </Grid>
          <Grid size={{ xs: 12, sm: 4, md: 2 }}>
            <TextField select fullWidth size="small" label="Status" value={filters.status} onChange={(e) => setFilter('status', e.target.value)}>
              <MenuItem value="">All</MenuItem>
              <MenuItem value="Completed">Completed</MenuItem>
              <MenuItem value="Cancelled">Cancelled</MenuItem>
            </TextField>
          </Grid>
          <Grid size={{ xs: 6, sm: 4, md: 3 }}>
            <TextField fullWidth size="small" type="date" label="From" value={filters.start_date} onChange={(e) => setFilter('start_date', e.target.value)} slotProps={{ inputLabel: { shrink: true } }} />
          </Grid>
          <Grid size={{ xs: 6, sm: 4, md: 3 }}>
            <TextField fullWidth size="small" type="date" label="To" value={filters.end_date} onChange={(e) => setFilter('end_date', e.target.value)} slotProps={{ inputLabel: { shrink: true } }} />
          </Grid>
        </Grid>
      </Paper>
      <TableContainer component={Paper}>
        <Table size="small" sx={{ minWidth: 860 }}>
          <TableHead>
            <TableRow sx={{ backgroundColor: '#f5f5f5' }}>
              <TableCell><strong>Redemption No.</strong></TableCell>
              <TableCell><strong>Date</strong></TableCell>
              <TableCell><strong>Customer</strong></TableCell>
              <TableCell><strong>Reward</strong></TableCell>
              <TableCell align="right"><strong>Points</strong></TableCell>
              <TableCell><strong>Branch</strong></TableCell>
              <TableCell><strong>Status</strong></TableCell>
              <TableCell><strong>Processed By</strong></TableCell>
              {can('redemptions.cancel') && <TableCell />}
            </TableRow>
          </TableHead>
          <TableBody>
            <TableStateRow colSpan={9} loading={loading} empty={!loading && rows.data.length === 0} message="No redemptions recorded" />
            {!loading && rows.data.map((r) => (
              <TableRow key={r.id} hover>
                <TableCell>{r.redemption_number}</TableCell>
                <TableCell>{formatDateTime(r.created_at)}</TableCell>
                <TableCell>{r.customer?.full_name}<Typography variant="caption" sx={{ display: 'block' }} color="text.secondary">{r.customer?.customer_code}</Typography></TableCell>
                <TableCell>{r.quantity} x {r.reward?.name}</TableCell>
                <TableCell align="right">{formatNumber(r.points_used)}</TableCell>
                <TableCell>{r.branch?.name || '-'}</TableCell>
                <TableCell>
                  <StatusChip status={r.status} />
                  {r.cancel_reason && <Typography variant="caption" sx={{ display: 'block' }} color="text.secondary">{r.cancel_reason}</Typography>}
                </TableCell>
                <TableCell>{r.processed_by?.name}</TableCell>
                {can('redemptions.cancel') && (
                  <TableCell>
                    {r.status === 'Completed' && <Button size="small" color="error" onClick={() => setCancelling(r)}>Cancel</Button>}
                  </TableCell>
                )}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </TableContainer>
      <TablePagination
        component="div" rowsPerPageOptions={[15, 30, 50]} count={rows.total || 0} page={page} rowsPerPage={rowsPerPage}
        onPageChange={(_, p) => setPage(p)}
        onRowsPerPageChange={(e) => { setRowsPerPage(parseInt(e.target.value, 10)); setPage(0); }}
      />
      <ConfirmDialog
        open={Boolean(cancelling)}
        title={`Cancel ${cancelling?.redemption_number}`}
        message={`${formatNumber(cancelling?.points_used)} points will be refunded to the customer and reward stock restored.`}
        confirmText="Cancel Redemption"
        color="error"
        loading={working}
        disabled={reason.trim().length < 5}
        onConfirm={cancel}
        onClose={() => { setCancelling(null); setReason(''); }}
      >
        <TextField fullWidth label="Reason (min. 5 characters)" value={reason} onChange={(e) => setReason(e.target.value)} />
      </ConfirmDialog>
    </Box>
  );
};

// ---------------------------------------------------------------------------
// Rewards catalogue
// ---------------------------------------------------------------------------
const emptyReward = {
  code: '', name: '', description: '', points_required: '', stock_quantity: '', max_per_customer: '',
  min_membership_level_id: '', is_active: true, valid_from: '', valid_until: '',
};

const RewardsCatalogue = () => {
  const { can } = useAuth();
  const notify = useNotify();
  const options = useOptions();
  const manage = can('rewards.manage');
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(emptyReward);
  const [errors, setErrors] = useState({});
  const [deleting, setDeleting] = useState(null);
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await rewardService.getAll({ per_page: 100 });
      setRows(res.data.data);
    } catch (error) {
      notify(apiError(error, 'Failed to load rewards').message, 'error');
    } finally {
      setLoading(false);
    }
  }, [notify]);

  useEffect(() => {
    load();
  }, [load]);

  const open = (reward = null) => {
    setErrors({});
    setForm(reward ? Object.fromEntries(Object.keys(emptyReward).map((k) => [k, reward[k] ?? (k === 'is_active' ? true : '')])) : emptyReward);
    setEditing(reward || 'new');
  };

  const save = async () => {
    setSaving(true);
    try {
      const payload = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v]));
      const res = editing === 'new' ? await rewardService.create(payload) : await rewardService.update(editing.id, payload);
      notify(res.message);
      setEditing(null);
      load();
    } catch (error) {
      const err = apiError(error, 'Failed to save reward');
      setErrors(err.fieldErrors);
      notify(err.message, 'error');
    } finally {
      setSaving(false);
    }
  };

  const remove = async () => {
    try {
      const res = await rewardService.delete(deleting.id);
      notify(res.message);
      setDeleting(null);
      load();
    } catch (error) {
      notify(apiError(error).message, 'error');
    }
  };

  const field = (name, label, props = {}) => (
    <TextField fullWidth size="small" label={label} value={form[name] ?? ''} onChange={(e) => setForm({ ...form, [name]: e.target.value })}
      error={Boolean(errors[name])} helperText={errors[name] || props.helperText} {...props} />
  );

  return (
    <Box>
      {manage && (
        <Box sx={{ display: 'flex', justifyContent: 'flex-end', mb: 2 }}>
          <Button variant="contained" startIcon={<Add />} onClick={() => open()} sx={{ backgroundColor: '#1a237e' }}>Add Reward</Button>
        </Box>
      )}
      <TableContainer component={Paper}>
        <Table size="small" sx={{ minWidth: 860 }}>
          <TableHead>
            <TableRow sx={{ backgroundColor: '#f5f5f5' }}>
              <TableCell><strong>Reward</strong></TableCell>
              <TableCell align="right"><strong>Points</strong></TableCell>
              <TableCell align="right"><strong>Stock</strong></TableCell>
              <TableCell><strong>Rules</strong></TableCell>
              <TableCell><strong>Validity</strong></TableCell>
              <TableCell align="right"><strong>Redeemed</strong></TableCell>
              <TableCell><strong>Availability</strong></TableCell>
              {manage && <TableCell />}
            </TableRow>
          </TableHead>
          <TableBody>
            <TableStateRow colSpan={8} loading={loading} empty={!loading && rows.length === 0} message="No rewards in the catalogue" />
            {!loading && rows.map((r) => (
              <TableRow key={r.id} hover>
                <TableCell>
                  <Typography variant="body2"><strong>{r.name}</strong></Typography>
                  <Typography variant="caption" color="text.secondary">{r.code} · {r.description}</Typography>
                </TableCell>
                <TableCell align="right">{formatNumber(r.points_required)}</TableCell>
                <TableCell align="right">{r.stock_quantity ?? 'Unlimited'}</TableCell>
                <TableCell>
                  <Typography variant="caption" component="div">{r.max_per_customer ? `Max ${r.max_per_customer} / customer` : 'No limit'}</Typography>
                  <Typography variant="caption" component="div">{r.min_level ? `${r.min_level.name}+` : 'All members'}</Typography>
                </TableCell>
                <TableCell>
                  <Typography variant="caption">
                    {r.valid_from ? formatDate(r.valid_from) : 'Any'} – {r.valid_until ? formatDate(r.valid_until) : 'Open'}
                  </Typography>
                </TableCell>
                <TableCell align="right">{formatNumber(r.redeemed_quantity || 0)}</TableCell>
                <TableCell>
                  <Chip size="small" color={r.is_available ? 'success' : 'default'} label={r.is_available ? 'Available' : r.is_active ? 'Unavailable' : 'Inactive'} />
                </TableCell>
                {manage && (
                  <TableCell>
                    <Tooltip title="Edit"><IconButton size="small" onClick={() => open(r)}><Edit fontSize="small" /></IconButton></Tooltip>
                    <Tooltip title="Remove"><IconButton size="small" color="error" onClick={() => setDeleting(r)}><Delete fontSize="small" /></IconButton></Tooltip>
                  </TableCell>
                )}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </TableContainer>

      <Dialog open={Boolean(editing)} onClose={saving ? undefined : () => setEditing(null)} maxWidth="sm" fullWidth>
        <DialogTitle>{editing === 'new' ? 'Add Reward' : 'Edit Reward'}</DialogTitle>
        <DialogContent dividers>
          <Grid container spacing={2}>
            <Grid size={{ xs: 12, sm: 4 }}>{field('code', 'Code', { required: true })}</Grid>
            <Grid size={{ xs: 12, sm: 8 }}>{field('name', 'Name', { required: true })}</Grid>
            <Grid size={12}>{field('description', 'Description', { multiline: true, minRows: 2 })}</Grid>
            <Grid size={{ xs: 12, sm: 4 }}>{field('points_required', 'Points required', { type: 'number', required: true })}</Grid>
            <Grid size={{ xs: 6, sm: 4 }}>{field('stock_quantity', 'Stock', { type: 'number', helperText: 'Empty = unlimited' })}</Grid>
            <Grid size={{ xs: 6, sm: 4 }}>{field('max_per_customer', 'Max per customer', { type: 'number' })}</Grid>
            <Grid size={{ xs: 12, sm: 6 }}>
              {field('min_membership_level_id', 'Minimum level', {
                select: true,
                children: [<MenuItem key="" value="">All members</MenuItem>, ...options.membership_levels.map((l) => <MenuItem key={l.id} value={l.id}>{l.name}</MenuItem>)],
              })}
            </Grid>
            <Grid size={{ xs: 12, sm: 6 }}>
              <FormControlLabel control={<Switch checked={Boolean(form.is_active)} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />} label="Active" />
            </Grid>
            <Grid size={6}>{field('valid_from', 'Valid from', { type: 'date', slotProps: { inputLabel: { shrink: true } } })}</Grid>
            <Grid size={6}>{field('valid_until', 'Valid until', { type: 'date', slotProps: { inputLabel: { shrink: true } } })}</Grid>
          </Grid>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setEditing(null)} disabled={saving}>Cancel</Button>
          <Button variant="contained" onClick={save} disabled={saving || !form.code || !form.name || !form.points_required} sx={{ backgroundColor: '#1a237e' }}>Save</Button>
        </DialogActions>
      </Dialog>

      <ConfirmDialog
        open={Boolean(deleting)}
        title="Remove reward"
        message={`Remove "${deleting?.name}" from the catalogue? Past redemptions keep their record.`}
        confirmText="Remove"
        color="error"
        onConfirm={remove}
        onClose={() => setDeleting(null)}
      />
    </Box>
  );
};

const RewardRedemption = () => {
  const { can } = useAuth();
  const [searchParams] = useSearchParams();
  const [refreshKey, setRefreshKey] = useState(0);

  const tabs = [
    { label: 'Process Redemption', show: can('redemptions.process') },
    { label: 'Redemption History', show: can('redemptions.view') },
    { label: 'Rewards Catalogue', show: can('rewards.view') },
  ].filter((t) => t.show);
  const [tab, setTab] = useState(0);
  const current = tabs[tab]?.label;

  return (
    <Box>
      <PageHeader title="Reward Redemption" subtitle="Verify points, validate eligibility, confirm redemptions and manage the rewards catalogue" />
      <Paper sx={{ mb: 2 }}>
        <Tabs value={tab} onChange={(_, v) => setTab(v)} variant="scrollable">
          {tabs.map((t) => <Tab key={t.label} label={t.label} />)}
        </Tabs>
      </Paper>
      {current === 'Process Redemption' && (
        <ProcessRedemption initialCustomerId={searchParams.get('customer')} onRedeemed={() => setRefreshKey((k) => k + 1)} />
      )}
      {current === 'Redemption History' && <RedemptionHistory refreshKey={refreshKey} />}
      {current === 'Rewards Catalogue' && <RewardsCatalogue />}
    </Box>
  );
};

export default RewardRedemption;
