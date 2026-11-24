import React, { useCallback, useEffect, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Chip,
  CircularProgress,
  Grid,
  MenuItem,
  Paper,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  TextField,
  Tooltip,
  Typography,
} from '@mui/material';
import {
  Refresh as RefreshIcon,
  Replay as ReplayIcon,
  Send as SendIcon,
  CheckCircle as OnlineIcon,
  Cancel as OfflineIcon,
  RemoveCircleOutline as NotConfiguredIcon,
} from '@mui/icons-material';
import { integrationService } from '../../services/api';
import { useNotify } from '../../context/NotifyContext';
import { apiError, formatDateTime } from '../../utils/helpers';
import { TableStateRow } from '../common/UI';

const STATUS_COLOR = { pending: 'warning', sent: 'success', failed: 'error' };

const HealthCard = ({ code, result }) => {
  const configured = result?.configured !== false;
  const ok = result?.ok;
  const Icon = !configured ? NotConfiguredIcon : ok ? OnlineIcon : OfflineIcon;
  const color = !configured ? 'text.disabled' : ok ? 'success.main' : 'error.main';

  return (
    <Paper variant="outlined" sx={{ p: 2, display: 'flex', alignItems: 'center', gap: 1.5 }}>
      <Icon sx={{ color, fontSize: 32 }} />
      <Box>
        <Typography variant="subtitle1" sx={{ fontWeight: 600 }}>{code}</Typography>
        <Typography variant="caption" color="text.secondary">{result ? result.message : 'Checking...'}</Typography>
      </Box>
    </Paper>
  );
};

/**
 * Settings → Integrations (Admin lang): health ng 4 na system, outbox na may
 * Retry, mga benta na failed ang stock sync, at ang huling 100 na logs.
 */
const IntegrationsPanel = () => {
  const notify = useNotify();
  const [summary, setSummary] = useState(null);
  const [health, setHealth] = useState(null);
  const [outbox, setOutbox] = useState({ data: [], total: 0 });
  const [logs, setLogs] = useState([]);
  const [status, setStatus] = useState('');
  const [loading, setLoading] = useState(true);
  const [working, setWorking] = useState(false);

  const loadOutbox = useCallback(async () => {
    const res = await integrationService.outbox({ status: status || undefined, per_page: 50 });
    setOutbox(res.data);
  }, [status]);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [s, l] = await Promise.all([integrationService.summary(), integrationService.logs()]);
      setSummary(s.data);
      setLogs(l.data);
      await loadOutbox();
    } catch (error) {
      notify(apiError(error, 'Failed to load integrations').message, 'error');
    } finally {
      setLoading(false);
    }
    // Hiwalay ang health, kasi puwedeng matagal kapag down ang isang system.
    setHealth(null);
    integrationService.health().then((res) => setHealth(res.data)).catch(() => setHealth({}));
  }, [loadOutbox, notify]);

  useEffect(() => {
    load();
  }, [load]);

  const retry = async (row) => {
    setWorking(true);
    try {
      const res = await integrationService.retry(row.id);
      notify(res.message);
      await load();
    } catch (error) {
      notify(apiError(error).message, 'error');
    } finally {
      setWorking(false);
    }
  };

  const processNow = async () => {
    setWorking(true);
    try {
      const res = await integrationService.processNow();
      notify(res.data.output || res.message);
      await load();
    } catch (error) {
      notify(apiError(error).message, 'error');
    } finally {
      setWorking(false);
    }
  };

  return (
    <Paper sx={{ p: 3 }}>
      <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 2, flexWrap: 'wrap', gap: 1 }}>
        <Box>
          <Typography variant="h6" sx={{ color: '#1a237e' }}>Integrations</Typography>
          <Typography variant="body2" color="text.secondary">
            Connection of the CRM with Procurement (PROC), Inventory (INV) and HR.
          </Typography>
        </Box>
        <Box sx={{ display: 'flex', gap: 1 }}>
          <Button startIcon={<RefreshIcon />} onClick={load} disabled={loading || working}>Refresh</Button>
          <Button variant="contained" startIcon={<SendIcon />} onClick={processNow} disabled={working} sx={{ backgroundColor: '#1a237e' }}>
            Send pending now
          </Button>
        </Box>
      </Box>

      {summary && (
        <Alert severity={summary.enabled ? 'success' : 'info'} sx={{ mb: 2 }}>
          {summary.enabled
            ? 'INTEGRATION_ENABLED=true: pending requests are sent every minute.'
            : 'INTEGRATION_ENABLED=false (Yugto 1): requests are kept as "pending" and nothing is sent yet.'}
          {!summary.api_key_set && ' Warning: INTEGRATION_API_KEY is empty, so other systems cannot call the CRM.'}
        </Alert>
      )}

      <Typography variant="subtitle2" gutterBottom>System health</Typography>
      <Grid container spacing={2} sx={{ mb: 3 }}>
        {['CRM', 'PROC', 'INV', 'HR'].map((code) => (
          <Grid key={code} size={{ xs: 6, md: 3 }}>
            <HealthCard code={code} result={health ? health[code] || { ok: false, message: 'Unknown' } : null} />
          </Grid>
        ))}
      </Grid>

      {summary?.failed_sales?.length > 0 && (
        <Alert severity="warning" sx={{ mb: 2 }}>
          <strong>Sales with failed stock sync:</strong>{' '}
          {summary.failed_sales.map((o) => o.transaction_number).join(', ')}.
          {' '}Check the logs below (e.g. a product without an INV SKU) and use Retry when fixed.
        </Alert>
      )}
      {summary?.failed_forwards?.length > 0 && (
        <Alert severity="warning" sx={{ mb: 2 }}>
          <strong>Complaints not delivered to Procurement:</strong>{' '}
          {summary.failed_forwards.map((f) => f.feedback_number).join(', ')}.
        </Alert>
      )}

      <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 1, gap: 1, flexWrap: 'wrap' }}>
        <Typography variant="subtitle2">
          Outbox
          {summary && ` (pending ${summary.outbox_counts.pending || 0} · sent ${summary.outbox_counts.sent || 0} · failed ${summary.outbox_counts.failed || 0})`}
        </Typography>
        <TextField select size="small" label="Status" value={status} onChange={(e) => setStatus(e.target.value)} sx={{ minWidth: 160 }}>
          <MenuItem value="">All</MenuItem>
          <MenuItem value="pending">Pending</MenuItem>
          <MenuItem value="sent">Sent</MenuItem>
          <MenuItem value="failed">Failed</MenuItem>
        </TextField>
      </Box>
      <TableContainer component={Paper} variant="outlined" sx={{ mb: 3 }}>
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell>Reference</TableCell>
              <TableCell>To</TableCell>
              <TableCell>Endpoint</TableCell>
              <TableCell>Status</TableCell>
              <TableCell align="right">Attempts</TableCell>
              <TableCell>Last error</TableCell>
              <TableCell>Created</TableCell>
              <TableCell align="right">Action</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            <TableStateRow colSpan={8} loading={loading} empty={!loading && outbox.data.length === 0} message="The outbox is empty" />
            {!loading && outbox.data.map((row) => (
              <TableRow key={row.id} hover>
                <TableCell sx={{ fontFamily: 'monospace' }}>{row.reference_number}</TableCell>
                <TableCell>{row.target_system}</TableCell>
                <TableCell>{row.method} {row.endpoint}</TableCell>
                <TableCell><Chip size="small" label={row.status} color={STATUS_COLOR[row.status] || 'default'} /></TableCell>
                <TableCell align="right">{row.attempts}</TableCell>
                <TableCell sx={{ maxWidth: 280 }}>
                  <Tooltip title={row.last_error || ''}>
                    <Typography variant="caption" noWrap component="div">{row.last_error || '-'}</Typography>
                  </Tooltip>
                </TableCell>
                <TableCell>{formatDateTime(row.created_at)}</TableCell>
                <TableCell align="right">
                  {row.status === 'failed' && (
                    <Button size="small" startIcon={<ReplayIcon />} disabled={working} onClick={() => retry(row)}>Retry</Button>
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </TableContainer>

      <Typography variant="subtitle2" gutterBottom>Last 100 integration logs</Typography>
      <TableContainer component={Paper} variant="outlined" sx={{ maxHeight: 420 }}>
        <Table size="small" stickyHeader>
          <TableHead>
            <TableRow>
              <TableCell>Time</TableCell>
              <TableCell>Dir</TableCell>
              <TableCell>System</TableCell>
              <TableCell>Request</TableCell>
              <TableCell>Reference</TableCell>
              <TableCell align="right">Status</TableCell>
              <TableCell align="right">ms</TableCell>
              <TableCell>Error</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            <TableStateRow colSpan={8} loading={loading} empty={!loading && logs.length === 0} message="No integration calls yet" />
            {!loading && logs.map((log) => (
              <TableRow key={log.id} hover>
                <TableCell>{formatDateTime(log.created_at)}</TableCell>
                <TableCell><Chip size="small" variant="outlined" label={log.direction} /></TableCell>
                <TableCell>{log.system}</TableCell>
                <TableCell sx={{ maxWidth: 320 }}>
                  <Typography variant="caption" noWrap component="div">{log.method} {log.url}</Typography>
                </TableCell>
                <TableCell sx={{ fontFamily: 'monospace' }}>{log.reference_number || '-'}</TableCell>
                <TableCell align="right">
                  {log.status_code ? (
                    <Chip size="small" label={log.status_code} color={log.status_code < 300 ? 'success' : log.status_code < 500 ? 'warning' : 'error'} />
                  ) : '-'}
                </TableCell>
                <TableCell align="right">{log.duration_ms ?? '-'}</TableCell>
                <TableCell sx={{ maxWidth: 280 }}>
                  <Tooltip title={log.error || ''}>
                    <Typography variant="caption" color="error" noWrap component="div">{log.error || ''}</Typography>
                  </Tooltip>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </TableContainer>

      {summary && (
        <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 2 }}>
          Employees copied from HR: {summary.employees_count}
          {summary.sync_cursors.length > 0 && ` · Last sync: ${summary.sync_cursors.map((c) => `${c.resource} ${formatDateTime(c.last_run_at)}`).join(', ')}`}
        </Typography>
      )}
      {working && <CircularProgress size={20} sx={{ position: 'fixed', bottom: 24, right: 24 }} />}
    </Paper>
  );
};

export default IntegrationsPanel;
