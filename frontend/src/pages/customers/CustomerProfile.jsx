import React, { useCallback, useEffect, useState } from 'react';
import {
  Alert,
  Avatar,
  Box,
  Button,
  Card,
  CardContent,
  Checkbox,
  Chip,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControlLabel,
  Grid,
  LinearProgress,
  MenuItem,
  Paper,
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
  Typography,
} from '@mui/material';
import {
  ArrowBack,
  CardMembership,
  Edit,
  HowToReg,
  PlayCircle,
  Redeem,
  SwapVert,
  Tune,
  VerifiedUser,
} from '@mui/icons-material';
import { useNavigate, useParams } from 'react-router-dom';
import { customerService, loyaltyService, redemptionService } from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { useNotify } from '../../context/NotifyContext';
import { useOptions } from '../../hooks/useOptions';
import { apiError, formatCurrency, formatDate, formatDateTime, formatNumber } from '../../utils/helpers';
import { ConfirmDialog, InfoRow, StatCard, StatusChip, TableStateRow } from '../../components/common/UI';
import CustomerForm from '../../components/customers/CustomerForm';
import { validateCustomer } from '../../utils/customerValidation';
import DuplicateList from '../../components/customers/DuplicateList';
import PurchaseTable from '../../components/customers/PurchaseTable';
import LoyaltyLedger, { AdjustPointsDialog } from '../../components/customers/LoyaltyLedger';
import FeedbackManagement from './FeedbackManagement';

const IDENTITY_FIELDS = ['full_name', 'phone', 'email', 'birthday'];

// ---------------------------------------------------------------------------
// Edit dialog (Module 6: account update with identity verification)
// ---------------------------------------------------------------------------
const EditCustomerDialog = ({ customer, onClose, onSaved }) => {
  const notify = useNotify();
  const options = useOptions();
  const original = {
    full_name: customer.full_name,
    phone: customer.phone,
    email: customer.email || '',
    birthday: customer.birthday || '',
    address: customer.address || '',
    preferred_branch_id: customer.preferred_branch_id || '',
    preferred_contact_method: customer.preferred_contact_method,
    customer_type: customer.customer_type,
    notes: customer.notes || '',
  };
  const [values, setValues] = useState(original);
  const [errors, setErrors] = useState({});
  const [context, setContext] = useState({ identity_verified: false, verification_method: '', request_channel: 'In-store', notify_customer: true });
  const [duplicates, setDuplicates] = useState([]);
  const [needsConfirm, setNeedsConfirm] = useState(false);
  const [saving, setSaving] = useState(false);

  const changed = Object.keys(values).filter((k) => String(values[k] ?? '') !== String(original[k] ?? ''));
  const identityChange = changed.some((f) => IDENTITY_FIELDS.includes(f));

  const save = async (confirmNotDuplicate = false) => {
    const clientErrors = validateCustomer(values);
    if (identityChange && !context.identity_verified) clientErrors.identity = 'Confirm the customer identity before changing name, contact details or birthday.';
    if (context.identity_verified && !context.verification_method) clientErrors.verification_method = 'Select the verification method.';
    setErrors(clientErrors);
    if (Object.keys(clientErrors).length) return;

    setSaving(true);
    try {
      const payload = Object.fromEntries(changed.map((k) => [k, values[k] === '' ? null : values[k]]));
      const res = await customerService.update(customer.id, {
        ...payload,
        identity_verified: context.identity_verified,
        verification_method: context.identity_verified ? context.verification_method : null,
        request_channel: context.request_channel,
        notify_customer: context.notify_customer,
        confirm_not_duplicate: confirmNotDuplicate,
      });
      notify(res.message);
      onSaved();
      onClose();
    } catch (error) {
      const err = apiError(error, 'Failed to update customer');
      if (err.status === 409) {
        setDuplicates(err.data?.duplicates || []);
        setNeedsConfirm(Boolean(err.data?.requires_confirmation));
      }
      setErrors(err.fieldErrors);
      notify(err.message, 'error');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open onClose={saving ? undefined : onClose} maxWidth="md" fullWidth>
      <DialogTitle>Update Account - {customer.customer_code}</DialogTitle>
      <DialogContent dividers>
        <Alert severity="info" sx={{ mb: 2 }}>
          Customer ID {customer.customer_code} and Membership Number {customer.membership_number} are retained. Every change is recorded in the account history.
        </Alert>
        <CustomerForm values={values} onChange={(name, value) => setValues((p) => ({ ...p, [name]: value }))} errors={errors} mode="edit" />
        <DuplicateList duplicates={duplicates} />
        <Paper variant="outlined" sx={{ p: 2, mt: 2 }}>
          <Typography variant="subtitle2" gutterBottom>Update request</Typography>
          <Grid container spacing={2} alignItems="center">
            <Grid size={{ xs: 12, md: 4 }}>
              <TextField select fullWidth size="small" label="Request received via" value={context.request_channel} onChange={(e) => setContext({ ...context, request_channel: e.target.value })}>
                {options.registration_channels.map((c) => <MenuItem key={c} value={c}>{c}</MenuItem>)}
              </TextField>
            </Grid>
            <Grid size={{ xs: 12, md: 4 }}>
              <FormControlLabel
                control={<Checkbox checked={context.identity_verified} onChange={(e) => setContext({ ...context, identity_verified: e.target.checked })} />}
                label="Identity verified"
              />
            </Grid>
            <Grid size={{ xs: 12, md: 4 }}>
              <TextField select fullWidth size="small" label="Verification method" value={context.verification_method} disabled={!context.identity_verified}
                error={Boolean(errors.verification_method)} helperText={errors.verification_method}
                onChange={(e) => setContext({ ...context, verification_method: e.target.value })}>
                {options.verification_methods.map((m) => <MenuItem key={m} value={m}>{m}</MenuItem>)}
              </TextField>
            </Grid>
            <Grid size={12}>
              <FormControlLabel
                control={<Checkbox checked={context.notify_customer} onChange={(e) => setContext({ ...context, notify_customer: e.target.checked })} />}
                label="Send update confirmation to the customer"
              />
            </Grid>
          </Grid>
          {errors.identity && <Alert severity="warning" sx={{ mt: 1 }}>{errors.identity}</Alert>}
        </Paper>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={saving}>Cancel</Button>
        {needsConfirm && <Button color="warning" onClick={() => save(true)} disabled={saving}>Not a Duplicate - Save</Button>}
        <Button variant="contained" onClick={() => save(false)} disabled={saving || changed.length === 0} sx={{ backgroundColor: '#1a237e' }}
          startIcon={saving ? <CircularProgress size={16} color="inherit" /> : null}>
          Save Changes ({changed.length})
        </Button>
      </DialogActions>
    </Dialog>
  );
};

// ---------------------------------------------------------------------------
// Tabs
// ---------------------------------------------------------------------------
const AccountHistory = ({ customerId, refreshKey }) => {
  const [rows, setRows] = useState({ data: [], total: 0 });
  const [page, setPage] = useState(0);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let active = true;
    customerService.accountLogs(customerId, { page: page + 1, per_page: 20 })
      .then((res) => active && setRows(res.data))
      .catch(() => {})
      .finally(() => active && setLoading(false));
    return () => { active = false; };
  }, [customerId, page, refreshKey]);

  const renderChanges = (changes) => {
    if (!changes) return null;
    return Object.entries(changes).map(([field, change]) => (
      <Typography key={field} variant="caption" sx={{ display: 'block' }} color="text.secondary">
        {field.replace(/_/g, ' ')}: {change && typeof change === 'object' && 'new' in change
          ? `${change.old ?? '-'} → ${change.new ?? '-'}`
          : String(typeof change === 'object' ? JSON.stringify(change) : change)}
      </Typography>
    ));
  };

  return (
    <>
      <TableContainer component={Paper}>
        <Table size="small" sx={{ minWidth: 700 }}>
          <TableHead>
            <TableRow sx={{ backgroundColor: '#f5f5f5' }}>
              <TableCell><strong>Date</strong></TableCell>
              <TableCell><strong>Action</strong></TableCell>
              <TableCell><strong>Details</strong></TableCell>
              <TableCell><strong>Verification</strong></TableCell>
              <TableCell><strong>Staff</strong></TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            <TableStateRow colSpan={5} loading={loading} empty={!loading && rows.data.length === 0} message="No account history" />
            {!loading && rows.data.map((log) => (
              <TableRow key={log.id}>
                <TableCell>{formatDateTime(log.created_at)}</TableCell>
                <TableCell><Chip size="small" label={log.action.replace(/_/g, ' ')} /></TableCell>
                <TableCell>
                  <Typography variant="body2">{log.description}</Typography>
                  {renderChanges(log.changes)}
                </TableCell>
                <TableCell>
                  {log.identity_verified ? <Chip size="small" color="success" label={log.verification_method || 'Verified'} /> : '-'}
                  {log.request_channel && <Typography variant="caption" sx={{ display: 'block' }}>via {log.request_channel}</Typography>}
                </TableCell>
                <TableCell>{log.user?.name || 'System'}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </TableContainer>
      <TablePagination component="div" rowsPerPageOptions={[20]} count={rows.total || 0} page={page} rowsPerPage={20} onPageChange={(_, p) => setPage(p)} />
    </>
  );
};

const NotificationHistory = ({ customerId }) => {
  const options = useOptions();
  const [rows, setRows] = useState({ data: [], total: 0 });
  const [page, setPage] = useState(0);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let active = true;
    customerService.notifications(customerId, { page: page + 1, per_page: 20 })
      .then((res) => active && setRows(res.data))
      .catch(() => {})
      .finally(() => active && setLoading(false));
    return () => { active = false; };
  }, [customerId, page]);

  return (
    <>
      <Alert severity="info" variant="outlined" sx={{ mb: 2 }}>
        Notifications are delivered by e-mail using the configured mail service. Skipped entries show why a message was not sent (e.g. no e-mail on file; SMS is not configured).
      </Alert>
      <TableContainer component={Paper}>
        <Table size="small" sx={{ minWidth: 700 }}>
          <TableHead>
            <TableRow sx={{ backgroundColor: '#f5f5f5' }}>
              <TableCell><strong>Date</strong></TableCell>
              <TableCell><strong>Type</strong></TableCell>
              <TableCell><strong>Subject</strong></TableCell>
              <TableCell><strong>Channel</strong></TableCell>
              <TableCell><strong>Status</strong></TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            <TableStateRow colSpan={5} loading={loading} empty={!loading && rows.data.length === 0} message="No notifications sent" />
            {!loading && rows.data.map((n) => (
              <TableRow key={n.id}>
                <TableCell>{formatDateTime(n.created_at)}</TableCell>
                <TableCell>{options.notification_types[n.type] || n.type}</TableCell>
                <TableCell>
                  <Typography variant="body2">{n.subject}</Typography>
                  {n.error && <Typography variant="caption" color="text.secondary">{n.error}</Typography>}
                </TableCell>
                <TableCell>{n.channel}{n.recipient ? ` (${n.recipient})` : ''}</TableCell>
                <TableCell><StatusChip status={n.status} /></TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </TableContainer>
      <TablePagination component="div" rowsPerPageOptions={[20]} count={rows.total || 0} page={page} rowsPerPage={20} onPageChange={(_, p) => setPage(p)} />
    </>
  );
};

const RedemptionHistory = ({ customerId, refreshKey }) => {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let active = true;
    redemptionService.getAll({ customer_id: customerId, per_page: 50 })
      .then((res) => active && setRows(res.data.data))
      .catch(() => {})
      .finally(() => active && setLoading(false));
    return () => { active = false; };
  }, [customerId, refreshKey]);

  return (
    <TableContainer component={Paper}>
      <Table size="small" sx={{ minWidth: 640 }}>
        <TableHead>
          <TableRow sx={{ backgroundColor: '#f5f5f5' }}>
            <TableCell><strong>Redemption No.</strong></TableCell>
            <TableCell><strong>Date</strong></TableCell>
            <TableCell><strong>Reward</strong></TableCell>
            <TableCell align="right"><strong>Qty</strong></TableCell>
            <TableCell align="right"><strong>Points</strong></TableCell>
            <TableCell><strong>Status</strong></TableCell>
            <TableCell><strong>Processed by</strong></TableCell>
          </TableRow>
        </TableHead>
        <TableBody>
          <TableStateRow colSpan={7} loading={loading} empty={!loading && rows.length === 0} message="No redemptions yet" />
          {!loading && rows.map((r) => (
            <TableRow key={r.id}>
              <TableCell>{r.redemption_number}</TableCell>
              <TableCell>{formatDateTime(r.created_at)}</TableCell>
              <TableCell>{r.reward?.name}</TableCell>
              <TableCell align="right">{r.quantity}</TableCell>
              <TableCell align="right">{formatNumber(r.points_used)}</TableCell>
              <TableCell><StatusChip status={r.status} /></TableCell>
              <TableCell>{r.processed_by?.name}</TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </TableContainer>
  );
};

// ---------------------------------------------------------------------------
// Profile page
// ---------------------------------------------------------------------------
const CustomerProfile = () => {
  const { id } = useParams();
  const navigate = useNavigate();
  const notify = useNotify();
  const { can } = useAuth();
  const options = useOptions();

  const [customer, setCustomer] = useState(null);
  const [loyalty, setLoyalty] = useState(null);
  const [loading, setLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);
  const [tab, setTab] = useState(0);
  const [dialog, setDialog] = useState(null);
  const [form, setForm] = useState({});
  const [working, setWorking] = useState(false);
  const [refreshKey, setRefreshKey] = useState(0);

  const load = useCallback(async () => {
    try {
      const [customerRes, loyaltyRes] = await Promise.all([
        customerService.getById(id),
        can('loyalty.view') ? loyaltyService.customer(id, { per_page: 1 }) : Promise.resolve(null),
      ]);
      setCustomer(customerRes.data);
      setLoyalty(loyaltyRes?.data || null);
    } catch (error) {
      if (error.response?.status === 404) setNotFound(true);
      else notify(apiError(error, 'Failed to load customer').message, 'error');
    } finally {
      setLoading(false);
    }
  }, [id, can, notify]);

  useEffect(() => {
    load();
  }, [load]);

  const refresh = () => {
    load();
    setRefreshKey((k) => k + 1);
  };

  const openDialog = (name, initial = {}) => {
    setForm(initial);
    setDialog(name);
  };

  const run = async (action) => {
    setWorking(true);
    try {
      const res = await action();
      notify(res.message);
      setDialog(null);
      refresh();
    } catch (error) {
      notify(apiError(error).message, 'error');
    } finally {
      setWorking(false);
    }
  };

  if (loading) return <LinearProgress />;
  if (notFound || !customer) {
    return (
      <Box>
        <Alert severity="error" sx={{ mb: 2 }}>Customer not found.</Alert>
        <Button startIcon={<ArrowBack />} onClick={() => navigate('/customers')}>Back to customers</Button>
      </Box>
    );
  }

  const membership = customer.membership;
  const isMember = membership?.is_loyalty_member;
  const archived = customer.status === 'Archived';

  const tabs = [
    { label: 'Overview', show: true },
    { label: 'Purchase History', show: can('customers.purchases') },
    { label: 'Loyalty Points', show: can('loyalty.view') },
    { label: 'Redemptions', show: can('redemptions.view') },
    { label: 'Feedback', show: can('feedback.view') },
    { label: 'Account History', show: true },
    { label: 'Notifications', show: true },
  ].filter((t) => t.show);
  const current = tabs[tab]?.label;

  return (
    <Box>
      <Button startIcon={<ArrowBack />} onClick={() => navigate(-1)} sx={{ mb: 1 }}>Back</Button>

      {/* Header */}
      <Card sx={{ mb: 3 }}>
        <CardContent>
          <Box sx={{ display: 'flex', gap: 2, alignItems: { xs: 'flex-start', md: 'center' }, flexDirection: { xs: 'column', md: 'row' } }}>
            <Avatar sx={{ bgcolor: '#1a237e', width: 64, height: 64, fontSize: 28 }}>{customer.full_name.charAt(0)}</Avatar>
            <Box sx={{ flexGrow: 1 }}>
              <Box sx={{ display: 'flex', gap: 1, alignItems: 'center', flexWrap: 'wrap' }}>
                <Typography variant="h5">{customer.full_name}</Typography>
                <StatusChip status={customer.status} />
                {membership?.level && <Chip size="small" icon={<CardMembership />} label={membership.level.name} color="primary" variant="outlined" />}
              </Box>
              <Typography variant="body2" color="text.secondary">
                Customer ID <strong>{customer.customer_code}</strong> · Membership No. <strong>{customer.membership_number}</strong> · {customer.customer_type}
              </Typography>
              {customer.status_reason && <Typography variant="caption" color="text.secondary">Status reason: {customer.status_reason}</Typography>}
            </Box>
            <Box sx={{ display: 'flex', gap: 1, flexWrap: 'wrap' }}>
              {can('customers.update') && !archived && (
                <Button size="small" variant="outlined" startIcon={<Edit />} onClick={() => setDialog('edit')}>Edit</Button>
              )}
              {can('customers.verify') && !customer.verified_at && !['Blocked', 'Archived'].includes(customer.status) && (
                <Button size="small" variant="outlined" color="success" startIcon={<VerifiedUser />}
                  onClick={() => openDialog('verify', { verification_method: '', notes: '' })}>Verify Identity</Button>
              )}
              {can('customers.status') && customer.verified_at && ['Verified', 'Inactive', 'Suspended'].includes(customer.status) && (
                <Button size="small" variant="contained" color="success" startIcon={<PlayCircle />} onClick={() => setDialog('activate')}>Activate</Button>
              )}
              {can('customers.status') && (
                <Button size="small" variant="outlined" startIcon={<SwapVert />} onClick={() => openDialog('status', { status: '', reason: '' })}>Change Status</Button>
              )}
              {can('loyalty.enroll') && !isMember && customer.can_transact && (
                <Button size="small" variant="contained" startIcon={<HowToReg />} sx={{ backgroundColor: '#1a237e' }} onClick={() => setDialog('enroll')}>
                  Enroll in Loyalty
                </Button>
              )}
              {can('redemptions.process') && isMember && customer.can_transact && (
                <Button size="small" variant="outlined" startIcon={<Redeem />} onClick={() => navigate(`/rewards?customer=${customer.id}`)}>Redeem Reward</Button>
              )}
              {can('loyalty.adjust') && isMember && (
                <Button size="small" variant="outlined" startIcon={<Tune />} onClick={() => setDialog('adjust')}>Adjust Points</Button>
              )}
              {can('loyalty.configure') && (
                <Button size="small" variant="outlined" startIcon={<CardMembership />}
                  onClick={() => openDialog('level', { membership_level_id: membership?.level?.id || '', reason: '', lock: true })}>Set Level</Button>
              )}
            </Box>
          </Box>
        </CardContent>
      </Card>

      {customer.status === 'Pending Verification' && (
        <Alert severity="warning" sx={{ mb: 2 }}>This account is pending verification. Verify the customer identity, then activate the account to make it eligible for the loyalty program.</Alert>
      )}

      {/* Summary */}
      <Grid container spacing={2} sx={{ mb: 3 }}>
        <Grid size={{ xs: 6, md: 3 }}>
          <StatCard label="Points Balance" value={isMember ? formatNumber(membership.points_balance) : 'Not enrolled'} hint={isMember ? `Lifetime earned ${formatNumber(membership.lifetime_points_earned)}` : undefined} />
        </Grid>
        <Grid size={{ xs: 6, md: 3 }}>
          <StatCard label="Total Spent" value={formatCurrency(customer.total_spent || 0)} hint={`${customer.orders_count || 0} transactions`} />
        </Grid>
        <Grid size={{ xs: 6, md: 3 }}>
          <StatCard label="Last Purchase" value={customer.last_purchase_at ? formatDate(customer.last_purchase_at) : 'Never'} />
        </Grid>
        <Grid size={{ xs: 6, md: 3 }}>
          <StatCard
            label="Next Level"
            value={loyalty?.next_level ? loyalty.next_level.name : '-'}
            hint={loyalty?.next_level ? `${formatNumber(loyalty.next_level.points_needed)} lifetime points to go` : 'No configured upgrade threshold'}
          />
        </Grid>
      </Grid>

      <Paper sx={{ mb: 2 }}>
        <Tabs value={tab} onChange={(_, v) => setTab(v)} variant="scrollable" scrollButtons="auto">
          {tabs.map((t) => <Tab key={t.label} label={t.label} />)}
        </Tabs>
      </Paper>

      {current === 'Overview' && (
        <Grid container spacing={2}>
          <Grid size={{ xs: 12, md: 6 }}>
            <Card sx={{ height: '100%' }}>
              <CardContent>
                <Typography variant="h6" gutterBottom>Profile</Typography>
                <Grid container spacing={1}>
                  <Grid size={6}><InfoRow label="Contact Number" value={customer.phone} /></Grid>
                  <Grid size={6}><InfoRow label="E-mail" value={customer.email || '-'} /></Grid>
                  {'birthday' in customer && <Grid size={6}><InfoRow label="Birthday" value={customer.birthday ? formatDate(customer.birthday) : '-'} /></Grid>}
                  <Grid size={6}><InfoRow label="Preferred Contact" value={customer.preferred_contact_method} /></Grid>
                  <Grid size={6}><InfoRow label="Preferred Branch" value={customer.preferred_branch?.name || 'No preference'} /></Grid>
                  <Grid size={6}><InfoRow label="Customer Type" value={customer.customer_type} /></Grid>
                  {'address' in customer && <Grid size={12}><InfoRow label="Address" value={customer.address || '-'} /></Grid>}
                  {customer.notes && <Grid size={12}><InfoRow label="Notes" value={customer.notes} /></Grid>}
                </Grid>
              </CardContent>
            </Card>
          </Grid>
          <Grid size={{ xs: 12, md: 6 }}>
            <Card sx={{ height: '100%' }}>
              <CardContent>
                <Typography variant="h6" gutterBottom>Account</Typography>
                <Grid container spacing={1}>
                  <Grid size={6}><InfoRow label="Registered" value={`${formatDate(customer.registration_date)} via ${customer.registration_channel}`} /></Grid>
                  <Grid size={6}><InfoRow label="Registered By" value={customer.registered_by?.name || '-'} /></Grid>
                  <Grid size={6}><InfoRow label="Verified" value={customer.verified_at ? `${formatDateTime(customer.verified_at)}${customer.verified_by ? ` by ${customer.verified_by.name}` : ''}` : 'Not verified'} /></Grid>
                  {'verification_method' in customer && <Grid size={6}><InfoRow label="Verification Method" value={customer.verification_method || '-'} /></Grid>}
                  <Grid size={6}><InfoRow label="Activated" value={customer.activated_at ? formatDateTime(customer.activated_at) : '-'} /></Grid>
                  <Grid size={6}><InfoRow label="Loyalty Enrolled" value={membership?.loyalty_enrolled_at ? formatDateTime(membership.loyalty_enrolled_at) : 'Not enrolled'} /></Grid>
                  <Grid size={6}><InfoRow label="Feedback Records" value={customer.feedback_count ?? 0} /></Grid>
                  <Grid size={6}><InfoRow label="Redemptions" value={customer.redemptions_count ?? 0} /></Grid>
                </Grid>
                {loyalty && !loyalty.program_configured && (
                  <Alert severity="info" variant="outlined" sx={{ mt: 1 }}>Loyalty points earning is not configured yet.</Alert>
                )}
              </CardContent>
            </Card>
          </Grid>
        </Grid>
      )}
      {current === 'Purchase History' && <PurchaseTable customerId={customer.id} />}
      {current === 'Loyalty Points' && <LoyaltyLedger customerId={customer.id} refreshKey={refreshKey} />}
      {current === 'Redemptions' && <RedemptionHistory customerId={customer.id} refreshKey={refreshKey} />}
      {current === 'Feedback' && <FeedbackManagement embedded customer={customer} />}
      {current === 'Account History' && <AccountHistory customerId={customer.id} refreshKey={refreshKey} />}
      {current === 'Notifications' && <NotificationHistory customerId={customer.id} />}

      {/* Dialogs */}
      {dialog === 'edit' && <EditCustomerDialog customer={customer} onClose={() => setDialog(null)} onSaved={refresh} />}

      <ConfirmDialog
        open={dialog === 'verify'}
        title="Verify customer identity"
        message="Confirm the customer's identity using a valid document or confirmation method."
        confirmText="Mark as Verified"
        color="success"
        loading={working}
        disabled={!form.verification_method}
        onConfirm={() => run(() => customerService.verify(customer.id, { verification_method: form.verification_method, notes: form.notes || null }))}
        onClose={() => setDialog(null)}
      >
        <TextField select fullWidth label="Verification method" value={form.verification_method || ''} onChange={(e) => setForm({ ...form, verification_method: e.target.value })} sx={{ mb: 2 }}>
          {options.verification_methods.map((m) => <MenuItem key={m} value={m}>{m}</MenuItem>)}
        </TextField>
        <TextField fullWidth label="Notes (optional)" value={form.notes || ''} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
      </ConfirmDialog>

      <ConfirmDialog
        open={dialog === 'activate'}
        title="Activate account"
        message={`Activate ${customer.full_name}'s account? The customer becomes eligible for the loyalty program and a notification is sent.`}
        confirmText="Activate"
        color="success"
        loading={working}
        onConfirm={() => run(() => customerService.activate(customer.id))}
        onClose={() => setDialog(null)}
      />

      <ConfirmDialog
        open={dialog === 'status'}
        title="Change account status"
        message={`Current status: ${customer.status}. A reason is required for Inactive, Suspended, Blocked and Archived.`}
        confirmText="Change Status"
        loading={working}
        disabled={!form.status || (['Inactive', 'Suspended', 'Blocked', 'Archived'].includes(form.status) && !form.reason?.trim())}
        onConfirm={() => run(() => customerService.changeStatus(customer.id, { status: form.status, reason: form.reason || null }))}
        onClose={() => setDialog(null)}
      >
        <TextField select fullWidth label="New status" value={form.status || ''} onChange={(e) => setForm({ ...form, status: e.target.value })} sx={{ mb: 2 }}>
          {options.customer_statuses.filter((s) => s !== customer.status).map((s) => <MenuItem key={s} value={s}>{s}</MenuItem>)}
        </TextField>
        <TextField fullWidth label="Reason" value={form.reason || ''} onChange={(e) => setForm({ ...form, reason: e.target.value })} />
      </ConfirmDialog>

      <ConfirmDialog
        open={dialog === 'enroll'}
        title="Enroll in the loyalty program"
        message={`Enroll ${customer.full_name} (${customer.membership_number}) in the ShopLink loyalty program? Eligible completed purchases will earn points according to the configured rules.`}
        confirmText="Enroll"
        loading={working}
        onConfirm={() => run(() => loyaltyService.enroll(customer.id))}
        onClose={() => setDialog(null)}
      />

      <ConfirmDialog
        open={dialog === 'level'}
        title="Set membership level"
        message="Manually assigned levels are locked against automatic upgrades unless unlocked."
        confirmText="Update Level"
        loading={working}
        disabled={!form.membership_level_id || !form.reason?.trim()}
        onConfirm={() => run(() => loyaltyService.setLevel(customer.id, form))}
        onClose={() => setDialog(null)}
      >
        <TextField select fullWidth label="Membership level" value={form.membership_level_id || ''} onChange={(e) => setForm({ ...form, membership_level_id: e.target.value })} sx={{ mb: 2 }}>
          {options.membership_levels.map((l) => <MenuItem key={l.id} value={l.id}>{l.name}</MenuItem>)}
        </TextField>
        <TextField fullWidth label="Reason" value={form.reason || ''} onChange={(e) => setForm({ ...form, reason: e.target.value })} sx={{ mb: 1 }} />
        <FormControlLabel control={<Checkbox checked={Boolean(form.lock)} onChange={(e) => setForm({ ...form, lock: e.target.checked })} />} label="Lock level (no automatic upgrades)" />
      </ConfirmDialog>

      {dialog === 'adjust' && <AdjustPointsDialog customer={customer} onClose={() => setDialog(null)} onDone={refresh} />}
    </Box>
  );
};

export default CustomerProfile;
