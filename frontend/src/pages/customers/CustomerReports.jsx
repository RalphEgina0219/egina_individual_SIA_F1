import React, { useCallback, useEffect, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Card,
  CardContent,
  Checkbox,
  FormControlLabel,
  Grid,
  LinearProgress,
  MenuItem,
  Paper,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from '@mui/material';
import { Campaign, Download, Cake } from '@mui/icons-material';
import { format, subDays } from 'date-fns';
import { Link as RouterLink } from 'react-router-dom';
import { customerManagementService } from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { useNotify } from '../../context/NotifyContext';
import { useOptions } from '../../hooks/useOptions';
import { apiError, cleanParams, downloadCsv, formatCurrency, formatDate, formatNumber } from '../../utils/helpers';
import { ConfirmDialog, PageHeader, StatCard, StatusChip } from '../../components/common/UI';
import { CategoryBarChart, ChartCard } from '../../components/common/Charts';
import { LEDGER_TYPES, SERIES } from '../../utils/constants';

const SectionTable = ({ title, action, headers, rows, empty = 'No records' }) => (
  <Card sx={{ height: '100%' }}>
    <CardContent>
      <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 1, gap: 1, flexWrap: 'wrap' }}>
        <Typography variant="subtitle1" sx={{ fontWeight: 600 }}>{title}</Typography>
        {action}
      </Box>
      <Box sx={{ overflowX: 'auto' }}>
        <Table size="small">
          <TableHead><TableRow>{headers.map((h) => <TableCell key={h.label} align={h.align}>{h.label}</TableCell>)}</TableRow></TableHead>
          <TableBody>
            {rows.length === 0 && <TableRow><TableCell colSpan={headers.length} align="center" sx={{ color: 'text.secondary' }}>{empty}</TableCell></TableRow>}
            {rows}
          </TableBody>
        </Table>
      </Box>
    </CardContent>
  </Card>
);

const CustomerReports = () => {
  const { can } = useAuth();
  const notify = useNotify();
  const options = useOptions();
  const [filters, setFilters] = useState({
    start_date: format(subDays(new Date(), 89), 'yyyy-MM-dd'),
    end_date: format(new Date(), 'yyyy-MM-dd'),
    branch_id: '',
    inactive_days: 90,
  });
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [announce, setAnnounce] = useState(null);
  const [sending, setSending] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await customerManagementService.reports(cleanParams(filters));
      setData(res.data);
    } catch (error) {
      notify(apiError(error, 'Failed to load reports').message, 'error');
    } finally {
      setLoading(false);
    }
  }, [filters, notify]);

  useEffect(() => {
    load();
  }, [load]);

  const sendAnnouncement = async () => {
    setSending(true);
    try {
      const payload = {
        subject: announce.subject,
        message: announce.message,
        customer_ids: announce.customer_ids || null,
        segment: announce.customer_ids ? null : cleanParams({
          membership_level_id: announce.membership_level_id,
          branch_id: announce.branch_id,
          loyalty_members_only: announce.loyalty_members_only || null,
        }),
      };
      const res = await customerManagementService.announce(payload);
      const results = Object.entries(res.data.results || {}).map(([k, v]) => `${v} ${k.toLowerCase()}`).join(', ');
      notify(`${res.message}${results ? `: ${results}` : ''}`);
      setAnnounce(null);
    } catch (error) {
      notify(apiError(error).message, 'error');
    } finally {
      setSending(false);
    }
  };

  const a = data?.activity;

  return (
    <Box>
      <PageHeader
        title="Customer Reports"
        subtitle="Segmentation, customer activity, inactive customers, loyalty and feedback reporting"
        actions={can('customers.notify') && (
          <Button variant="contained" startIcon={<Campaign />} sx={{ backgroundColor: '#1a237e' }}
            onClick={() => setAnnounce({ subject: '', message: '', membership_level_id: '', branch_id: '', loyalty_members_only: false })}>
            Send Announcement
          </Button>
        )}
      />

      <Paper sx={{ p: 2, mb: 3 }}>
        <Grid container spacing={2}>
          <Grid size={{ xs: 6, md: 3 }}>
            <TextField fullWidth size="small" type="date" label="From" value={filters.start_date} onChange={(e) => setFilters({ ...filters, start_date: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} />
          </Grid>
          <Grid size={{ xs: 6, md: 3 }}>
            <TextField fullWidth size="small" type="date" label="To" value={filters.end_date} onChange={(e) => setFilters({ ...filters, end_date: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} />
          </Grid>
          <Grid size={{ xs: 6, md: 3 }}>
            <TextField select fullWidth size="small" label="Branch" value={filters.branch_id} onChange={(e) => setFilters({ ...filters, branch_id: e.target.value })}>
              <MenuItem value="">All branches</MenuItem>
              {options.branches.map((b) => <MenuItem key={b.id} value={b.id}>{b.name}</MenuItem>)}
            </TextField>
          </Grid>
          <Grid size={{ xs: 6, md: 3 }}>
            <TextField select fullWidth size="small" label="Inactive if no purchase in" value={filters.inactive_days} onChange={(e) => setFilters({ ...filters, inactive_days: e.target.value })}>
              {[30, 60, 90, 180, 365].map((d) => <MenuItem key={d} value={d}>{d} days</MenuItem>)}
            </TextField>
          </Grid>
        </Grid>
      </Paper>

      {loading && <LinearProgress sx={{ mb: 2 }} />}

      {data && (
        <>
          <Grid container spacing={2} sx={{ mb: 3 }}>
            <Grid size={{ xs: 6, md: 3 }}><StatCard label="Purchasing Customers" value={formatNumber(a.purchasing_customers)} hint={`${formatNumber(a.returning_customers)} returning · ${formatNumber(a.new_purchasing_customers)} first-time`} /></Grid>
            <Grid size={{ xs: 6, md: 3 }}><StatCard label="Member Sales" value={formatCurrency(a.member_sales)} hint={`${formatNumber(a.member_transactions)} transactions`} /></Grid>
            <Grid size={{ xs: 6, md: 3 }}><StatCard label="Walk-in Sales" value={formatCurrency(a.walk_in_sales)} hint={`${formatNumber(a.walk_in_transactions)} transactions`} /></Grid>
            <Grid size={{ xs: 6, md: 3 }}><StatCard label="Inactive Customers" value={formatNumber(data.inactive_customers.count)} hint={`No purchase in ${data.filters.inactive_days} days`} color="#c62828" /></Grid>
          </Grid>

          <Typography variant="h6" sx={{ mb: 1 }}>Customer Segmentation</Typography>
          <Grid container spacing={2} sx={{ mb: 3 }}>
            <Grid size={{ xs: 12, md: 6 }}>
              <ChartCard title="By Membership Level" height={220} empty={!data.segmentation.by_level.length}>
                <CategoryBarChart data={data.segmentation.by_level} />
              </ChartCard>
            </Grid>
            <Grid size={{ xs: 12, md: 6 }}>
              <ChartCard title="By Customer Type" height={220} empty={!data.segmentation.by_type.length}>
                <CategoryBarChart data={data.segmentation.by_type} color={SERIES[1]} />
              </ChartCard>
            </Grid>
            <Grid size={{ xs: 12, md: 6 }}>
              <ChartCard title="By Preferred Branch" height={220} empty={!data.segmentation.by_branch.length}>
                <CategoryBarChart data={data.segmentation.by_branch} color={SERIES[2]} />
              </ChartCard>
            </Grid>
            <Grid size={{ xs: 12, md: 6 }}>
              <ChartCard title="By Account Status" height={220} empty={!data.segmentation.by_status.length}>
                <CategoryBarChart data={data.segmentation.by_status} color={SERIES[6]} />
              </ChartCard>
            </Grid>
          </Grid>

          <Grid container spacing={2} sx={{ mb: 3 }}>
            <Grid size={{ xs: 12, lg: 6 }}>
              <SectionTable
                title="Top Customers by Spend"
                action={<Button size="small" startIcon={<Download />} disabled={!data.top_customers.length}
                  onClick={() => downloadCsv('top-customers.csv', data.top_customers.map((c) => ({
                    customer_id: c.customer_code, name: c.full_name, level: c.membership_level, transactions: c.transactions, total_spent: c.total_spent, last_purchase: c.last_purchase_at,
                  })))}>CSV</Button>}
                headers={[{ label: 'Customer' }, { label: 'Level' }, { label: 'Transactions', align: 'right' }, { label: 'Total Spent', align: 'right' }]}
                rows={data.top_customers.map((c) => (
                  <TableRow key={c.id}>
                    <TableCell><RouterLink to={`/customers/${c.id}`} style={{ color: '#1a237e' }}>{c.full_name}</RouterLink><Typography variant="caption" sx={{ display: 'block' }} color="text.secondary">{c.customer_code}</Typography></TableCell>
                    <TableCell>{c.membership_level}</TableCell>
                    <TableCell align="right">{c.transactions}</TableCell>
                    <TableCell align="right">{formatCurrency(c.total_spent)}</TableCell>
                  </TableRow>
                ))}
                empty="No member purchases in this period"
              />
            </Grid>
            <Grid size={{ xs: 12, lg: 6 }}>
              <SectionTable
                title={`Inactive Customers (no purchase in ${data.filters.inactive_days} days)`}
                action={<Button size="small" startIcon={<Download />} disabled={!data.inactive_customers.list.length}
                  onClick={() => downloadCsv('inactive-customers.csv', data.inactive_customers.list.map((c) => ({
                    customer_id: c.customer_code, name: c.full_name, phone: c.phone, status: c.status, last_purchase: c.last_purchase_at || 'Never',
                  })))}>CSV</Button>}
                headers={[{ label: 'Customer' }, { label: 'Status' }, { label: 'Last Purchase' }]}
                rows={data.inactive_customers.list.map((c) => (
                  <TableRow key={c.id}>
                    <TableCell><RouterLink to={`/customers/${c.id}`} style={{ color: '#1a237e' }}>{c.full_name}</RouterLink><Typography variant="caption" sx={{ display: 'block' }} color="text.secondary">{c.customer_code} · {c.phone}</Typography></TableCell>
                    <TableCell><StatusChip status={c.status} /></TableCell>
                    <TableCell>{c.last_purchase_at ? formatDate(c.last_purchase_at) : 'Never'}</TableCell>
                  </TableRow>
                ))}
                empty="No inactive customers"
              />
              {data.inactive_customers.count > data.inactive_customers.list.length && (
                <Typography variant="caption" color="text.secondary">Showing {data.inactive_customers.list.length} of {data.inactive_customers.count}. Use All Customers → "No purchase in" filter for the full list.</Typography>
              )}
            </Grid>
          </Grid>

          <Grid container spacing={2} sx={{ mb: 3 }}>
            <Grid size={{ xs: 12, lg: 4 }}>
              <SectionTable
                title="Birthdays This Month"
                action={can('customers.notify') && data.birthdays_this_month.length > 0 && (
                  <Button size="small" startIcon={<Cake />} onClick={() => setAnnounce({
                    subject: 'Happy Birthday from ShopLink!',
                    message: 'Happy birthday! Visit any ShopLink Supermarket branch and ask our Customer Service desk about this month\'s birthday treats for members.',
                    customer_ids: data.birthdays_this_month.map((c) => c.id),
                  })}>Greet</Button>
                )}
                headers={[{ label: 'Customer' }, { label: 'Birthday' }, { label: 'Contact' }]}
                rows={data.birthdays_this_month.map((c) => (
                  <TableRow key={c.id}>
                    <TableCell>{c.full_name}</TableCell>
                    <TableCell>{formatDate(c.birthday, 'MMM d')}</TableCell>
                    <TableCell>{c.preferred_contact_method}</TableCell>
                  </TableRow>
                ))}
                empty="No birthdays this month"
              />
            </Grid>
            <Grid size={{ xs: 12, md: 6, lg: 4 }}>
              <SectionTable
                title={`Loyalty Activity · ${formatNumber(data.loyalty.points_outstanding)} pts outstanding`}
                headers={[{ label: 'Type' }, { label: 'Entries', align: 'right' }, { label: 'Points', align: 'right' }]}
                rows={data.loyalty.ledger.map((l) => (
                  <TableRow key={l.type}>
                    <TableCell>{LEDGER_TYPES[l.type]?.label || l.type}</TableCell>
                    <TableCell align="right">{l.transactions}</TableCell>
                    <TableCell align="right">{formatNumber(l.points)}</TableCell>
                  </TableRow>
                ))}
                empty="No points activity in this period"
              />
            </Grid>
            <Grid size={{ xs: 12, md: 6, lg: 4 }}>
              <SectionTable
                title="Top Redeemed Rewards"
                headers={[{ label: 'Reward' }, { label: 'Qty', align: 'right' }, { label: 'Points', align: 'right' }]}
                rows={data.loyalty.top_rewards.map((r) => (
                  <TableRow key={r.reward_id}>
                    <TableCell>{r.reward?.name}</TableCell>
                    <TableCell align="right">{r.quantity}</TableCell>
                    <TableCell align="right">{formatNumber(r.points)}</TableCell>
                  </TableRow>
                ))}
                empty="No redemptions in this period"
              />
            </Grid>
          </Grid>

          <Typography variant="h6" sx={{ mb: 1 }}>Feedback Summary</Typography>
          <Grid container spacing={2}>
            <Grid size={{ xs: 6, md: 3 }}><StatCard label="Average Rating" value={data.feedback.average_rating ? `${data.feedback.average_rating} / 5` : '-'} /></Grid>
            <Grid size={{ xs: 6, md: 3 }}>
              <StatCard label="Avg. Resolution Time" value={data.feedback.average_resolution_hours !== null ? `${data.feedback.average_resolution_hours} h` : '-'} hint={`${data.feedback.resolved_count} resolved`} />
            </Grid>
            <Grid size={{ xs: 12, md: 3 }}>
              <ChartCard title="By Channel" height={160} empty={!data.feedback.by_channel.length}>
                <CategoryBarChart data={data.feedback.by_channel} color={SERIES[2]} />
              </ChartCard>
            </Grid>
            <Grid size={{ xs: 12, md: 3 }}>
              <ChartCard title="By Department" height={160} empty={!data.feedback.by_department.length}>
                <CategoryBarChart data={data.feedback.by_department} color={SERIES[1]} />
              </ChartCard>
            </Grid>
          </Grid>
        </>
      )}

      <ConfirmDialog
        open={Boolean(announce)}
        title={announce?.customer_ids ? `Send to ${announce.customer_ids.length} customer(s)` : 'Send Promotional Announcement'}
        message="Sent by e-mail to verified/active customers (max. 500 per send). Customers whose contact preference is None are skipped. Each attempt is recorded in the customer's notification history."
        confirmText="Send"
        loading={sending}
        disabled={!announce?.subject?.trim() || !announce?.message?.trim()}
        onConfirm={sendAnnouncement}
        onClose={() => setAnnounce(null)}
      >
        {announce && (
          <Grid container spacing={2}>
            {!announce.customer_ids && (
              <>
                <Grid size={{ xs: 12, sm: 6 }}>
                  <TextField select fullWidth size="small" label="Membership level" value={announce.membership_level_id} onChange={(e) => setAnnounce({ ...announce, membership_level_id: e.target.value })}>
                    <MenuItem value="">All levels</MenuItem>
                    {options.membership_levels.map((l) => <MenuItem key={l.id} value={l.id}>{l.name}</MenuItem>)}
                  </TextField>
                </Grid>
                <Grid size={{ xs: 12, sm: 6 }}>
                  <TextField select fullWidth size="small" label="Preferred branch" value={announce.branch_id} onChange={(e) => setAnnounce({ ...announce, branch_id: e.target.value })}>
                    <MenuItem value="">All branches</MenuItem>
                    {options.branches.map((b) => <MenuItem key={b.id} value={b.id}>{b.name}</MenuItem>)}
                  </TextField>
                </Grid>
                <Grid size={12}>
                  <FormControlLabel control={<Checkbox checked={announce.loyalty_members_only} onChange={(e) => setAnnounce({ ...announce, loyalty_members_only: e.target.checked })} />} label="Loyalty members only" />
                </Grid>
              </>
            )}
            <Grid size={12}><TextField fullWidth size="small" label="Subject" value={announce.subject} onChange={(e) => setAnnounce({ ...announce, subject: e.target.value })} /></Grid>
            <Grid size={12}><TextField fullWidth multiline minRows={4} label="Message" value={announce.message} onChange={(e) => setAnnounce({ ...announce, message: e.target.value })} /></Grid>
          </Grid>
        )}
      </ConfirmDialog>
      <Alert severity="info" variant="outlined" sx={{ mt: 3 }}>
        Reports are generated on demand from current records; export tables to CSV for distribution.
      </Alert>
    </Box>
  );
};

export default CustomerReports;
