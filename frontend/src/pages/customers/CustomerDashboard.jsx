import React, { useCallback, useEffect, useState } from 'react';
import {
  Box,
  Button,
  Card,
  CardContent,
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
import {
  CardMembership,
  Feedback,
  HourglassEmpty,
  People,
  PersonOff,
  Refresh,
  ReportProblem,
  Stars,
  TaskAlt,
  TrendingDown,
  TrendingUp,
  Verified,
  WorkspacePremium,
} from '@mui/icons-material';
import { format, subDays } from 'date-fns';
import { useNavigate } from 'react-router-dom';
import { customerManagementService } from '../../services/api';
import { useNotify } from '../../context/NotifyContext';
import { useOptions } from '../../hooks/useOptions';
import { apiError, cleanParams, formatCurrency, formatDate, formatNumber } from '../../utils/helpers';
import { PageHeader, StatCard, StatusChip } from '../../components/common/UI';
import { CategoryBarChart, ChartCard, GroupedBarChart, TrendChart } from '../../components/common/Charts';
import { SERIES } from '../../utils/constants';

const today = () => format(new Date(), 'yyyy-MM-dd');

const CustomerDashboard = () => {
  const navigate = useNavigate();
  const notify = useNotify();
  const options = useOptions();
  const [filters, setFilters] = useState({ start_date: format(subDays(new Date(), 29), 'yyyy-MM-dd'), end_date: today(), branch_id: '' });
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await customerManagementService.dashboard(cleanParams(filters));
      setData(res.data);
    } catch (error) {
      notify(apiError(error, 'Failed to load dashboard').message, 'error');
    } finally {
      setLoading(false);
    }
  }, [filters, notify]);

  useEffect(() => {
    load();
  }, [load]);

  const preset = (days) => setFilters((f) => ({ ...f, start_date: format(subDays(new Date(), days - 1), 'yyyy-MM-dd'), end_date: today() }));

  const s = data?.stats;
  const charts = data?.charts;
  const sum = (rows, key) => (rows || []).reduce((t, r) => t + Number(r[key] || 0), 0);

  return (
    <Box>
      <PageHeader
        title="Customer Dashboard"
        subtitle="Live customer, loyalty and feedback figures from the ShopLink database"
        actions={<Button variant="outlined" startIcon={<Refresh />} onClick={load} disabled={loading}>Refresh</Button>}
      />

      {/* Filters: one row above the charts */}
      <Paper sx={{ p: 2, mb: 3 }}>
        <Grid container spacing={2} alignItems="center">
          <Grid size={{ xs: 6, md: 2 }}>
            <TextField fullWidth size="small" type="date" label="From" value={filters.start_date}
              onChange={(e) => setFilters({ ...filters, start_date: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} />
          </Grid>
          <Grid size={{ xs: 6, md: 2 }}>
            <TextField fullWidth size="small" type="date" label="To" value={filters.end_date}
              onChange={(e) => setFilters({ ...filters, end_date: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} />
          </Grid>
          <Grid size={{ xs: 12, md: 3 }}>
            <TextField select fullWidth size="small" label="Branch" value={filters.branch_id} onChange={(e) => setFilters({ ...filters, branch_id: e.target.value })}>
              <MenuItem value="">All branches</MenuItem>
              {options.branches.map((b) => <MenuItem key={b.id} value={b.id}>{b.name}</MenuItem>)}
            </TextField>
          </Grid>
          <Grid size={{ xs: 12, md: 5 }} sx={{ display: 'flex', gap: 1, flexWrap: 'wrap' }}>
            {[7, 30, 90, 365].map((d) => <Button key={d} size="small" variant="outlined" onClick={() => preset(d)}>{d === 365 ? '12 months' : `${d} days`}</Button>)}
          </Grid>
        </Grid>
        <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>
          Customer counts reflect current account status (branch = preferred branch). Points, feedback and transaction figures use the selected period.
        </Typography>
      </Paper>

      {loading && !data && <LinearProgress />}
      {loading && data && <LinearProgress sx={{ mb: 1 }} />}

      {s && (
        <>
          <Grid container spacing={2} sx={{ mb: 3 }}>
            <Grid size={{ xs: 6, md: 3 }}><StatCard label="Total Customers" value={formatNumber(s.total_customers)} icon={<People />} hint={`${formatNumber(s.new_registrations)} registered in period`} onClick={() => navigate('/customers')} /></Grid>
            <Grid size={{ xs: 6, md: 3 }}><StatCard label="Active Customers" value={formatNumber(s.active_customers)} icon={<Verified />} color="#1b7f3a" /></Grid>
            <Grid size={{ xs: 6, md: 3 }}><StatCard label="Inactive Customers" value={formatNumber(s.inactive_customers)} icon={<PersonOff />} color="#616161" /></Grid>
            <Grid size={{ xs: 6, md: 3 }}><StatCard label="Pending Verification" value={formatNumber(s.pending_verification)} icon={<HourglassEmpty />} color="#b26a00" /></Grid>
            <Grid size={{ xs: 6, md: 3 }}><StatCard label="Loyalty Members" value={formatNumber(s.loyalty_members)} icon={<CardMembership />} onClick={() => navigate('/loyalty/membership')} /></Grid>
            <Grid size={{ xs: 6, md: 3 }}><StatCard label="Gold Members" value={formatNumber(s.gold_members)} icon={<Stars />} color="#9a6b00" /></Grid>
            <Grid size={{ xs: 6, md: 3 }}><StatCard label="VIP Customers" value={formatNumber(s.vip_customers)} icon={<WorkspacePremium />} color="#6250d6" /></Grid>
            <Grid size={{ xs: 6, md: 3 }}><StatCard label="Points Outstanding" value={formatNumber(s.points_outstanding)} icon={<Stars />} hint="Current member balances" /></Grid>
            <Grid size={{ xs: 6, md: 3 }}><StatCard label="Points Issued" value={formatNumber(s.points_issued)} icon={<TrendingUp />} hint="In period" onClick={() => navigate('/loyalty/points')} /></Grid>
            <Grid size={{ xs: 6, md: 3 }}><StatCard label="Points Redeemed" value={formatNumber(s.points_redeemed)} icon={<TrendingDown />} hint="In period, net of refunds" /></Grid>
            <Grid size={{ xs: 6, md: 3 }}><StatCard label="Customer Feedback" value={formatNumber(s.total_feedback)} icon={<Feedback />} hint="Received in period" onClick={() => navigate('/feedback')} /></Grid>
            <Grid size={{ xs: 6, md: 3 }}>
              <StatCard label="Pending Complaints" value={formatNumber(s.pending_complaints)} icon={<ReportProblem />} color="#c62828"
                hint={`${formatNumber(s.resolved_complaints)} resolved in period`} onClick={() => navigate('/complaints')} />
            </Grid>
          </Grid>

          <Grid container spacing={2} sx={{ mb: 3 }}>
            <Grid size={{ xs: 12, lg: 6 }}>
              <ChartCard title="Customer Registration Trends" subtitle={`New accounts per ${data.filters.granularity}`} empty={sum(charts.registration_trend, 'registrations') === 0}>
                <TrendChart data={charts.registration_trend} series={[{ key: 'registrations', name: 'Registrations' }]} />
              </ChartCard>
            </Grid>
            <Grid size={{ xs: 12, lg: 6 }}>
              <ChartCard title="Customer Membership Distribution" subtitle="Customers by membership level" empty={!charts.membership_distribution.length}>
                <CategoryBarChart data={charts.membership_distribution} />
              </ChartCard>
            </Grid>
            <Grid size={{ xs: 12, lg: 6 }}>
              <ChartCard title="Customer Purchase Activity" subtitle="Member vs walk-in transactions (cancelled orders excluded)"
                empty={sum(charts.purchase_activity, 'member_transactions') + sum(charts.purchase_activity, 'walk_in_transactions') === 0}>
                <GroupedBarChart data={charts.purchase_activity} series={[
                  { key: 'member_transactions', name: 'Member transactions' },
                  { key: 'walk_in_transactions', name: 'Walk-in transactions' },
                ]} />
              </ChartCard>
            </Grid>
            <Grid size={{ xs: 12, lg: 6 }}>
              <ChartCard title="Loyalty Points Activity" subtitle="Points earned vs redeemed"
                empty={sum(charts.loyalty_activity, 'earned') + sum(charts.loyalty_activity, 'redeemed') === 0}>
                <TrendChart data={charts.loyalty_activity} series={[{ key: 'earned', name: 'Earned' }, { key: 'redeemed', name: 'Redeemed' }]} />
              </ChartCard>
            </Grid>
            <Grid size={{ xs: 12, lg: 6 }}>
              <ChartCard title="Customer Feedback Categories" subtitle="Feedback received in period" empty={!charts.feedback_categories.length}>
                <CategoryBarChart data={charts.feedback_categories} color={SERIES[2]} />
              </ChartCard>
            </Grid>
            <Grid size={{ xs: 12, lg: 6 }}>
              <ChartCard title="Complaint Status Distribution" subtitle="All complaints and product / service concerns" empty={sum(charts.complaint_status, 'value') === 0}>
                <CategoryBarChart data={charts.complaint_status} color={SERIES[1]} />
              </ChartCard>
            </Grid>
          </Grid>

          <Grid container spacing={2}>
            <Grid size={{ xs: 12, lg: 6 }}>
              <Card sx={{ height: '100%' }}>
                <CardContent>
                  <Typography variant="subtitle1" sx={{ fontWeight: 600, mb: 1 }}>Recent Customer Registrations</Typography>
                  <Box sx={{ overflowX: 'auto' }}>
                    <Table size="small">
                      <TableHead>
                        <TableRow><TableCell>Customer</TableCell><TableCell>Level</TableCell><TableCell>Status</TableCell><TableCell>Registered</TableCell></TableRow>
                      </TableHead>
                      <TableBody>
                        {data.recent_registrations.length === 0 && (
                          <TableRow><TableCell colSpan={4} align="center" sx={{ color: 'text.secondary' }}>No registrations yet</TableCell></TableRow>
                        )}
                        {data.recent_registrations.map((c) => (
                          <TableRow key={c.id} hover sx={{ cursor: 'pointer' }} onClick={() => navigate(`/customers/${c.id}`)}>
                            <TableCell>{c.full_name}<Typography variant="caption" sx={{ display: 'block' }} color="text.secondary">{c.customer_code}</Typography></TableCell>
                            <TableCell>{c.membership_level}</TableCell>
                            <TableCell><StatusChip status={c.status} /></TableCell>
                            <TableCell>{formatDate(c.created_at)}</TableCell>
                          </TableRow>
                        ))}
                      </TableBody>
                    </Table>
                  </Box>
                </CardContent>
              </Card>
            </Grid>
            <Grid size={{ xs: 12, lg: 6 }}>
              <Card sx={{ height: '100%' }}>
                <CardContent>
                  <Typography variant="subtitle1" sx={{ fontWeight: 600, mb: 1 }}>Recent Customer Transactions</Typography>
                  <Box sx={{ overflowX: 'auto' }}>
                    <Table size="small">
                      <TableHead>
                        <TableRow><TableCell>Transaction</TableCell><TableCell>Customer</TableCell><TableCell align="right">Amount</TableCell><TableCell>Status</TableCell></TableRow>
                      </TableHead>
                      <TableBody>
                        {data.recent_transactions.length === 0 && (
                          <TableRow><TableCell colSpan={4} align="center" sx={{ color: 'text.secondary' }}>No customer transactions yet</TableCell></TableRow>
                        )}
                        {data.recent_transactions.map((o) => (
                          <TableRow key={o.id} hover>
                            <TableCell>{o.transaction_number}<Typography variant="caption" sx={{ display: 'block' }} color="text.secondary">{formatDate(o.order_date)}</Typography></TableCell>
                            <TableCell>{o.customer?.full_name}</TableCell>
                            <TableCell align="right">{formatCurrency(o.total_amount)}</TableCell>
                            <TableCell><StatusChip status={o.order_status} /></TableCell>
                          </TableRow>
                        ))}
                      </TableBody>
                    </Table>
                  </Box>
                </CardContent>
              </Card>
            </Grid>
          </Grid>
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, mt: 2, color: 'text.secondary' }}>
            <TaskAlt fontSize="small" />
            <Typography variant="caption">All figures are computed from database records at request time.</Typography>
          </Box>
        </>
      )}
    </Box>
  );
};

export default CustomerDashboard;
