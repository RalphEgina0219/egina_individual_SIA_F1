import React, { useCallback, useEffect, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Card,
  CardContent,
  Chip,
  CircularProgress,
  FormControlLabel,
  Grid,
  LinearProgress,
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
  Typography,
} from '@mui/material';
import { Search, Save } from '@mui/icons-material';
import { useNavigate } from 'react-router-dom';
import { loyaltyService } from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { useNotify } from '../../context/NotifyContext';
import { refreshOptions } from '../../hooks/useOptions';
import { apiError, cleanParams, formatDate, formatDateTime, formatNumber } from '../../utils/helpers';
import { ConfirmDialog, PageHeader, StatusChip, TableStateRow } from '../../components/common/UI';

const Members = ({ levels }) => {
  const navigate = useNavigate();
  const notify = useNotify();
  const [filters, setFilters] = useState({ search: '', membership_level_id: '', enrolled: 'yes', near_upgrade: false, sort: 'enrolled' });
  const [page, setPage] = useState(0);
  const [rowsPerPage, setRowsPerPage] = useState(15);
  const [rows, setRows] = useState({ data: [], total: 0 });
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await loyaltyService.members(cleanParams({
        ...filters, near_upgrade: filters.near_upgrade ? 1 : null, page: page + 1, per_page: rowsPerPage,
      }));
      setRows(res.data);
    } catch (error) {
      notify(apiError(error, 'Failed to load members').message, 'error');
    } finally {
      setLoading(false);
    }
  }, [filters, page, rowsPerPage, notify]);

  useEffect(() => {
    load();
  }, [load]);

  const setFilter = (name, value) => {
    setFilters((prev) => ({ ...prev, [name]: value }));
    setPage(0);
  };

  return (
    <Box>
      <Paper sx={{ p: 2, mb: 2 }}>
        <Grid container spacing={2} alignItems="center">
          <Grid size={{ xs: 12, md: 4 }}>
            <TextField fullWidth size="small" placeholder="Name, Customer ID, membership no." value={filters.search}
              onChange={(e) => setFilter('search', e.target.value)}
              slotProps={{ input: { startAdornment: <Search sx={{ mr: 1, color: 'text.secondary' }} /> } }} />
          </Grid>
          <Grid size={{ xs: 6, md: 2 }}>
            <TextField select fullWidth size="small" label="Program" value={filters.enrolled} onChange={(e) => setFilter('enrolled', e.target.value)}>
              <MenuItem value="yes">Enrolled members</MenuItem>
              <MenuItem value="no">Not yet enrolled</MenuItem>
              <MenuItem value="all">All customers</MenuItem>
            </TextField>
          </Grid>
          <Grid size={{ xs: 6, md: 2 }}>
            <TextField select fullWidth size="small" label="Level" value={filters.membership_level_id} onChange={(e) => setFilter('membership_level_id', e.target.value)}>
              <MenuItem value="">All levels</MenuItem>
              {levels.map((l) => <MenuItem key={l.id} value={l.id}>{l.name}</MenuItem>)}
            </TextField>
          </Grid>
          <Grid size={{ xs: 6, md: 2 }}>
            <TextField select fullWidth size="small" label="Sort" value={filters.sort} onChange={(e) => setFilter('sort', e.target.value)}>
              <MenuItem value="enrolled">Recently enrolled</MenuItem>
              <MenuItem value="points">Points balance</MenuItem>
              <MenuItem value="lifetime">Lifetime points</MenuItem>
            </TextField>
          </Grid>
          <Grid size={{ xs: 6, md: 2 }}>
            <FormControlLabel control={<Switch checked={filters.near_upgrade} onChange={(e) => setFilter('near_upgrade', e.target.checked)} />} label="Near upgrade" />
          </Grid>
        </Grid>
      </Paper>

      <TableContainer component={Paper}>
        <Table size="small" sx={{ minWidth: 820 }}>
          <TableHead>
            <TableRow sx={{ backgroundColor: '#f5f5f5' }}>
              <TableCell><strong>Member</strong></TableCell>
              <TableCell><strong>Status</strong></TableCell>
              <TableCell><strong>Level</strong></TableCell>
              <TableCell align="right"><strong>Balance</strong></TableCell>
              <TableCell align="right"><strong>Lifetime Earned</strong></TableCell>
              <TableCell sx={{ minWidth: 200 }}><strong>Upgrade Progress</strong></TableCell>
              <TableCell><strong>Enrolled</strong></TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            <TableStateRow colSpan={7} loading={loading} empty={!loading && rows.data.length === 0} message="No members match the filters" />
            {!loading && rows.data.map((m) => {
              const next = m.next_level;
              const progress = next ? Math.min(100, (m.lifetime_points_earned / next.min_lifetime_points) * 100) : null;
              return (
                <TableRow key={m.id} hover sx={{ cursor: 'pointer' }} onClick={() => navigate(`/customers/${m.customer.id}`)}>
                  <TableCell>
                    <Typography variant="body2">{m.customer.full_name}</Typography>
                    <Typography variant="caption" color="text.secondary">{m.customer.customer_code} · {m.customer.membership_number}</Typography>
                  </TableCell>
                  <TableCell><StatusChip status={m.customer.status} /></TableCell>
                  <TableCell>
                    <Chip size="small" label={m.level.name} color="primary" variant="outlined" />
                    {m.level_locked && <Typography variant="caption" sx={{ display: 'block' }} color="text.secondary">Locked</Typography>}
                  </TableCell>
                  <TableCell align="right">{formatNumber(m.points_balance)}</TableCell>
                  <TableCell align="right">{formatNumber(m.lifetime_points_earned)}</TableCell>
                  <TableCell>
                    {next ? (
                      <>
                        <LinearProgress variant="determinate" value={progress} sx={{ height: 6, borderRadius: 3 }} />
                        <Typography variant="caption" color="text.secondary">
                          {formatNumber(next.points_needed)} pts to {next.name}
                        </Typography>
                      </>
                    ) : <Typography variant="caption" color="text.secondary">No configured next level</Typography>}
                  </TableCell>
                  <TableCell>{m.loyalty_enrolled_at ? formatDate(m.loyalty_enrolled_at) : 'Not enrolled'}</TableCell>
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
      </TableContainer>
      <TablePagination
        component="div" rowsPerPageOptions={[15, 30, 50]} count={rows.total || 0} page={page} rowsPerPage={rowsPerPage}
        onPageChange={(_, p) => setPage(p)}
        onRowsPerPageChange={(e) => { setRowsPerPage(parseInt(e.target.value, 10)); setPage(0); }}
      />
    </Box>
  );
};

const ProgramSettings = ({ data, onSaved }) => {
  const { can } = useAuth();
  const notify = useNotify();
  const editable = can('loyalty.configure');
  const [settings, setSettings] = useState({
    program_enabled: data.settings.program_enabled,
    amount_per_point: data.settings.amount_per_point ?? '',
    minimum_purchase_amount: data.settings.minimum_purchase_amount ?? 0,
    points_expiry_months: data.settings.points_expiry_months ?? '',
    auto_upgrade_enabled: data.settings.auto_upgrade_enabled,
  });
  const [levels, setLevels] = useState(data.levels.map((l) => ({ ...l, min_lifetime_points: l.min_lifetime_points ?? '', benefits: l.benefits ?? '', description: l.description ?? '' })));
  const [saving, setSaving] = useState(false);
  const [confirmExpiry, setConfirmExpiry] = useState(false);

  const saveSettings = async () => {
    setSaving(true);
    try {
      const res = await loyaltyService.updateSettings({
        ...settings,
        amount_per_point: settings.amount_per_point === '' ? null : Number(settings.amount_per_point),
        minimum_purchase_amount: Number(settings.minimum_purchase_amount || 0),
        points_expiry_months: settings.points_expiry_months === '' ? null : Number(settings.points_expiry_months),
      });
      notify(res.message);
      onSaved();
    } catch (error) {
      notify(apiError(error).message, 'error');
    } finally {
      setSaving(false);
    }
  };

  const saveLevel = async (level) => {
    try {
      const res = await loyaltyService.updateLevel(level.id, {
        min_lifetime_points: level.min_lifetime_points === '' ? null : Number(level.min_lifetime_points),
        points_multiplier: Number(level.points_multiplier),
        description: level.description || null,
        benefits: level.benefits || null,
        is_active: level.is_active,
      });
      notify(res.message);
      refreshOptions();
      onSaved();
    } catch (error) {
      notify(apiError(error).message, 'error');
    }
  };

  const setLevel = (id, field, value) => setLevels((prev) => prev.map((l) => (l.id === id ? { ...l, [field]: value } : l)));
  const baseLevel = (code) => ['REGULAR', 'REGISTERED', 'LOYALTY'].includes(code);

  const runExpiry = async () => {
    try {
      const res = await loyaltyService.runExpiry();
      notify(res.message);
      onSaved();
    } catch (error) {
      notify(apiError(error).message, 'error');
    } finally {
      setConfirmExpiry(false);
    }
  };

  return (
    <Box>
      {!data.is_configured && (
        <Alert severity="warning" sx={{ mb: 2 }}>
          Points earning is disabled or has no conversion rate. No points are awarded until an administrator sets the purchase amount per point and enables the program.
        </Alert>
      )}
      <Card sx={{ mb: 3 }}>
        <CardContent>
          <Typography variant="h6" gutterBottom>Earning Rules</Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
            Points = floor(order total ÷ amount per point × level multiplier), awarded when an order is marked <strong>{data.earning_order_status}</strong>.
            {data.last_updated?.updatedBy && ` Last changed by ${data.last_updated.updatedBy.name} on ${formatDateTime(data.last_updated.updated_at)}.`}
          </Typography>
          <Grid container spacing={2} alignItems="center">
            <Grid size={{ xs: 12, md: 3 }}>
              <FormControlLabel disabled={!editable}
                control={<Switch checked={settings.program_enabled} onChange={(e) => setSettings({ ...settings, program_enabled: e.target.checked })} />}
                label="Points earning enabled" />
            </Grid>
            <Grid size={{ xs: 12, sm: 6, md: 3 }}>
              <TextField fullWidth size="small" type="number" label="Purchase amount (PHP) per 1 point" disabled={!editable}
                value={settings.amount_per_point} onChange={(e) => setSettings({ ...settings, amount_per_point: e.target.value })}
                slotProps={{ htmlInput: { min: 0.01, step: '0.01' } }} />
            </Grid>
            <Grid size={{ xs: 12, sm: 6, md: 3 }}>
              <TextField fullWidth size="small" type="number" label="Minimum purchase (PHP)" disabled={!editable}
                value={settings.minimum_purchase_amount} onChange={(e) => setSettings({ ...settings, minimum_purchase_amount: e.target.value })}
                slotProps={{ htmlInput: { min: 0 } }} />
            </Grid>
            <Grid size={{ xs: 12, sm: 6, md: 3 }}>
              <TextField fullWidth size="small" type="number" label="Expire after inactivity (months)" disabled={!editable}
                helperText="Empty = points never expire"
                value={settings.points_expiry_months} onChange={(e) => setSettings({ ...settings, points_expiry_months: e.target.value })}
                slotProps={{ htmlInput: { min: 1 } }} />
            </Grid>
            <Grid size={{ xs: 12, sm: 6, md: 3 }}>
              <FormControlLabel disabled={!editable}
                control={<Switch checked={settings.auto_upgrade_enabled} onChange={(e) => setSettings({ ...settings, auto_upgrade_enabled: e.target.checked })} />}
                label="Automatic level upgrades" />
            </Grid>
          </Grid>
          {editable && (
            <Box sx={{ display: 'flex', gap: 1, mt: 2, flexWrap: 'wrap' }}>
              <Button variant="contained" startIcon={saving ? <CircularProgress size={16} color="inherit" /> : <Save />} onClick={saveSettings} disabled={saving} sx={{ backgroundColor: '#1a237e' }}>
                Save Rules
              </Button>
              {data.settings.points_expiry_months && (
                <Button variant="outlined" color="warning" onClick={() => setConfirmExpiry(true)}>Run Expiry Now</Button>
              )}
            </Box>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardContent>
          <Typography variant="h6" gutterBottom>Membership Levels</Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
            Regular → Registered → Loyalty Member are assigned by the account workflow. Gold Member and VIP upgrades happen automatically when a lifetime-points threshold is set, or manually from a customer profile.
          </Typography>
          <TableContainer>
            <Table size="small" sx={{ minWidth: 800 }}>
              <TableHead>
                <TableRow>
                  <TableCell>Level</TableCell>
                  <TableCell align="right">Customers</TableCell>
                  <TableCell>Upgrade at lifetime points</TableCell>
                  <TableCell>Points multiplier</TableCell>
                  <TableCell>Benefits</TableCell>
                  <TableCell>Active</TableCell>
                  {editable && <TableCell />}
                </TableRow>
              </TableHead>
              <TableBody>
                {levels.map((l) => (
                  <TableRow key={l.id}>
                    <TableCell>
                      <Typography variant="body2"><strong>{l.name}</strong></Typography>
                      <Typography variant="caption" color="text.secondary">{l.description}</Typography>
                    </TableCell>
                    <TableCell align="right">{l.memberships_count}</TableCell>
                    <TableCell>
                      {baseLevel(l.code) ? <Typography variant="caption" color="text.secondary">Workflow-assigned</Typography> : (
                        <TextField size="small" type="number" placeholder="Manual only" disabled={!editable} value={l.min_lifetime_points}
                          onChange={(e) => setLevel(l.id, 'min_lifetime_points', e.target.value)} sx={{ width: 140 }} />
                      )}
                    </TableCell>
                    <TableCell>
                      <TextField size="small" type="number" disabled={!editable} value={l.points_multiplier}
                        onChange={(e) => setLevel(l.id, 'points_multiplier', e.target.value)} sx={{ width: 100 }}
                        slotProps={{ htmlInput: { step: '0.1', min: 0 } }} />
                    </TableCell>
                    <TableCell>
                      <TextField size="small" fullWidth disabled={!editable} value={l.benefits} onChange={(e) => setLevel(l.id, 'benefits', e.target.value)} />
                    </TableCell>
                    <TableCell>
                      <Switch size="small" disabled={!editable} checked={l.is_active} onChange={(e) => setLevel(l.id, 'is_active', e.target.checked)} />
                    </TableCell>
                    {editable && (
                      <TableCell><Button size="small" onClick={() => saveLevel(l)}>Save</Button></TableCell>
                    )}
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </TableContainer>
        </CardContent>
      </Card>

      <ConfirmDialog
        open={confirmExpiry}
        title="Run points expiry"
        message={`Expire the full balance of members with no points activity in the last ${data.settings.points_expiry_months} months? This posts expiry entries to the ledger.`}
        confirmText="Expire Points"
        color="warning"
        onConfirm={runExpiry}
        onClose={() => setConfirmExpiry(false)}
      />
    </Box>
  );
};

const LoyaltyMembership = () => {
  const notify = useNotify();
  const [tab, setTab] = useState(0);
  const [data, setData] = useState(null);
  const [version, setVersion] = useState(0);

  const load = useCallback(() => loyaltyService.getSettings()
    .then((res) => {
      setData(res.data);
      setVersion((v) => v + 1);
    })
    .catch((error) => notify(apiError(error, 'Failed to load loyalty settings').message, 'error')), [notify]);

  useEffect(() => {
    load();
  }, [load]);

  return (
    <Box>
      <PageHeader title="Loyalty Membership" subtitle="Membership levels, enrolled members, upgrade monitoring and program rules" />
      {data && (
        <Grid container spacing={2} sx={{ mb: 2 }}>
          {data.levels.map((l) => (
            <Grid key={l.id} size={{ xs: 6, sm: 4, md: 'grow' }}>
              <Paper sx={{ p: 2, borderTop: 3, borderColor: '#1a237e' }}>
                <Typography variant="caption" color="text.secondary">{l.name}</Typography>
                <Typography variant="h5">{formatNumber(l.memberships_count)}</Typography>
              </Paper>
            </Grid>
          ))}
        </Grid>
      )}
      <Paper sx={{ mb: 2 }}>
        <Tabs value={tab} onChange={(_, v) => setTab(v)}>
          <Tab label="Members" />
          <Tab label="Program Rules & Levels" />
        </Tabs>
      </Paper>
      {!data ? <LinearProgress /> : tab === 0
        ? <Members levels={data.levels} />
        : <ProgramSettings key={version} data={data} onSaved={load} />}
    </Box>
  );
};

export default LoyaltyMembership;
