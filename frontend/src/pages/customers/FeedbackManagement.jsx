import React, { useCallback, useEffect, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Card,
  CardActionArea,
  CardContent,
  Chip,
  Grid,
  MenuItem,
  Paper,
  Rating,
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
import { Add as AddIcon, Search } from '@mui/icons-material';
import { differenceInDays } from 'date-fns';
import { feedbackService } from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { useNotify } from '../../context/NotifyContext';
import { useOptions } from '../../hooks/useOptions';
import { apiError, cleanParams, formatDateTime } from '../../utils/helpers';
import { PageHeader, StatusChip, TableStateRow } from '../../components/common/UI';
import { FeedbackDetailDialog, FeedbackFormDialog } from '../../components/customers/FeedbackDialogs';

/**
 * Module 4. `complaintsOnly` renders the Complaint Tracking view.
 */
const FeedbackManagement = ({ complaintsOnly = false, customer = null, embedded = false }) => {
  const customerId = customer?.id || null;
  const { can } = useAuth();
  const notify = useNotify();
  const options = useOptions();
  const canViewAll = can('feedback.view');

  const [filters, setFilters] = useState({
    search: '', status: complaintsOnly ? 'open' : '', type: '', category: '', channel: '', priority: '', department: '', branch_id: '', assigned_to_me: false,
  });
  const [page, setPage] = useState(0);
  const [rowsPerPage, setRowsPerPage] = useState(15);
  const [rows, setRows] = useState({ data: [], total: 0 });
  const [statusCounts, setStatusCounts] = useState({});
  const [loading, setLoading] = useState(true);
  const [formOpen, setFormOpen] = useState(false);
  const [selectedId, setSelectedId] = useState(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await feedbackService.getAll(cleanParams({
        ...filters,
        assigned_to_me: filters.assigned_to_me ? 1 : null,
        complaints_only: complaintsOnly ? 1 : null,
        customer_id: customerId,
        page: page + 1,
        per_page: rowsPerPage,
      }));
      setRows(res.data.feedback);
      setStatusCounts(res.data.status_counts || {});
    } catch (error) {
      notify(apiError(error, 'Failed to load feedback').message, 'error');
    } finally {
      setLoading(false);
    }
  }, [filters, complaintsOnly, customerId, page, rowsPerPage, notify]);

  useEffect(() => {
    load();
  }, [load]);

  const setFilter = (name, value) => {
    setFilters((prev) => ({ ...prev, [name]: value }));
    setPage(0);
  };

  const openCount = ['Pending', 'Under Review', 'Assigned', 'In Progress'].reduce((sum, s) => sum + (statusCounts[s] || 0), 0);

  return (
    <Box>
      {!embedded && (
        <PageHeader
          title={complaintsOnly ? 'Complaint Tracking' : 'Feedback Management'}
          subtitle={complaintsOnly
            ? (canViewAll ? 'Assign, investigate and resolve complaints and product / service concerns' : 'Concerns assigned to you for investigation')
            : 'Central record of feedback received in-store, via website, e-mail, phone and social media'}
          actions={can('feedback.record') && (
            <Button variant="contained" startIcon={<AddIcon />} onClick={() => setFormOpen(true)} sx={{ backgroundColor: '#1a237e' }}>
              {complaintsOnly ? 'Record Complaint' : 'Record Feedback'}
            </Button>
          )}
        />
      )}
      {embedded && can('feedback.record') && (
        <Box sx={{ display: 'flex', justifyContent: 'flex-end', mb: 2 }}>
          <Button variant="outlined" startIcon={<AddIcon />} onClick={() => setFormOpen(true)}>Record Feedback</Button>
        </Box>
      )}

      {!embedded && (
        <Grid container spacing={2} sx={{ mb: 2 }}>
          <Grid size={{ xs: 6, sm: 4, md: 'grow' }}>
            <Card sx={{ borderLeft: 4, borderColor: 'warning.main' }}>
              <CardActionArea onClick={() => setFilter('status', 'open')}>
                <CardContent sx={{ py: 1.5 }}>
                  <Typography variant="caption" color="text.secondary">Open</Typography>
                  <Typography variant="h5">{openCount}</Typography>
                </CardContent>
              </CardActionArea>
            </Card>
          </Grid>
          {options.feedback_statuses.map((s) => (
            <Grid key={s} size={{ xs: 6, sm: 4, md: 'grow' }}>
              <Card variant={filters.status === s ? 'elevation' : 'outlined'}>
                <CardActionArea onClick={() => setFilter('status', filters.status === s ? '' : s)}>
                  <CardContent sx={{ py: 1.5 }}>
                    <Typography variant="caption" color="text.secondary">{s}</Typography>
                    <Typography variant="h5">{statusCounts[s] || 0}</Typography>
                  </CardContent>
                </CardActionArea>
              </Card>
            </Grid>
          ))}
        </Grid>
      )}

      <Paper sx={{ p: 2, mb: 2 }}>
        <Grid container spacing={2}>
          <Grid size={{ xs: 12, md: 3 }}>
            <TextField
              fullWidth size="small" placeholder="Reference, subject, customer" value={filters.search}
              onChange={(e) => setFilter('search', e.target.value)}
              slotProps={{ input: { startAdornment: <Search sx={{ mr: 1, color: 'text.secondary' }} /> } }}
            />
          </Grid>
          <Grid size={{ xs: 6, md: 'grow' }}>
            <TextField select fullWidth size="small" label="Status" value={filters.status} onChange={(e) => setFilter('status', e.target.value)}>
              <MenuItem value="">All</MenuItem>
              <MenuItem value="open">Open (not resolved)</MenuItem>
              {options.feedback_statuses.map((s) => <MenuItem key={s} value={s}>{s}</MenuItem>)}
            </TextField>
          </Grid>
          <Grid size={{ xs: 6, md: 'grow' }}>
            <TextField select fullWidth size="small" label="Type" value={filters.type} onChange={(e) => setFilter('type', e.target.value)}>
              <MenuItem value="">All</MenuItem>
              {(complaintsOnly ? options.complaint_types : options.feedback_types).map((t) => <MenuItem key={t} value={t}>{t}</MenuItem>)}
            </TextField>
          </Grid>
          <Grid size={{ xs: 6, md: 'grow' }}>
            <TextField select fullWidth size="small" label="Category" value={filters.category} onChange={(e) => setFilter('category', e.target.value)}>
              <MenuItem value="">All</MenuItem>
              {options.feedback_categories.map((c) => <MenuItem key={c} value={c}>{c}</MenuItem>)}
            </TextField>
          </Grid>
          {complaintsOnly ? (
            <Grid size={{ xs: 6, md: 'grow' }}>
              <TextField select fullWidth size="small" label="Department" value={filters.department} onChange={(e) => setFilter('department', e.target.value)}>
                <MenuItem value="">All</MenuItem>
                {options.departments.map((d) => <MenuItem key={d} value={d}>{d}</MenuItem>)}
              </TextField>
            </Grid>
          ) : (
            <Grid size={{ xs: 6, md: 'grow' }}>
              <TextField select fullWidth size="small" label="Channel" value={filters.channel} onChange={(e) => setFilter('channel', e.target.value)}>
                <MenuItem value="">All</MenuItem>
                {options.feedback_channels.map((c) => <MenuItem key={c} value={c}>{c}</MenuItem>)}
              </TextField>
            </Grid>
          )}
          <Grid size={{ xs: 6, md: 'grow' }}>
            <TextField select fullWidth size="small" label="Priority" value={filters.priority} onChange={(e) => setFilter('priority', e.target.value)}>
              <MenuItem value="">All</MenuItem>
              {options.feedback_priorities.map((p) => <MenuItem key={p} value={p}>{p}</MenuItem>)}
            </TextField>
          </Grid>
          {canViewAll && complaintsOnly && (
            <Grid size={{ xs: 12, md: 'auto' }}>
              <Button fullWidth variant={filters.assigned_to_me ? 'contained' : 'outlined'} onClick={() => setFilter('assigned_to_me', !filters.assigned_to_me)}>
                Assigned to me
              </Button>
            </Grid>
          )}
        </Grid>
      </Paper>

      {!canViewAll && (
        <Alert severity="info" sx={{ mb: 2 }}>You are viewing only the concerns assigned to you.</Alert>
      )}

      <TableContainer component={Paper}>
        <Table size="small" sx={{ minWidth: 900 }}>
          <TableHead>
            <TableRow sx={{ backgroundColor: '#f5f5f5' }}>
              <TableCell><strong>Reference</strong></TableCell>
              <TableCell><strong>Subject</strong></TableCell>
              <TableCell><strong>Customer</strong></TableCell>
              <TableCell><strong>Type / Category</strong></TableCell>
              <TableCell><strong>Channel</strong></TableCell>
              <TableCell><strong>Priority</strong></TableCell>
              <TableCell><strong>Status</strong></TableCell>
              <TableCell><strong>{complaintsOnly ? 'Assigned' : 'Rating'}</strong></TableCell>
              <TableCell><strong>Received</strong></TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            <TableStateRow colSpan={9} loading={loading} empty={!loading && rows.data.length === 0} message={complaintsOnly ? 'No complaints found' : 'No feedback found'} />
            {!loading && rows.data.map((f) => {
              const age = differenceInDays(new Date(), new Date(f.received_at));
              const open = !['Resolved', 'Closed'].includes(f.status);
              return (
                <TableRow key={f.id} hover sx={{ cursor: 'pointer' }} onClick={() => setSelectedId(f.id)}>
                  <TableCell><Typography variant="body2" color="primary">{f.reference_number}</Typography></TableCell>
                  <TableCell sx={{ maxWidth: 240 }}><Typography variant="body2" noWrap>{f.subject}</Typography></TableCell>
                  <TableCell>{f.customer ? `${f.customer.full_name}` : f.contact_name || 'Anonymous'}</TableCell>
                  <TableCell>
                    <Typography variant="body2">{f.type}</Typography>
                    <Typography variant="caption" color="text.secondary">{f.category || 'Uncategorized'}</Typography>
                  </TableCell>
                  <TableCell>{f.channel}</TableCell>
                  <TableCell><StatusChip status={f.priority} variant="outlined" /></TableCell>
                  <TableCell><StatusChip status={f.status} /></TableCell>
                  <TableCell>
                    {complaintsOnly
                      ? <><Typography variant="body2">{f.department || '-'}</Typography><Typography variant="caption" color="text.secondary">{f.assignee?.name}</Typography></>
                      : f.rating ? <Rating value={f.rating} readOnly size="small" /> : '-'}
                  </TableCell>
                  <TableCell>
                    <Typography variant="body2">{formatDateTime(f.received_at)}</Typography>
                    {open && age >= 3 && <Chip size="small" color="error" variant="outlined" label={`${age} days open`} />}
                  </TableCell>
                </TableRow>
              );
            })}
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

      {formOpen && (
        <FeedbackFormDialog
          open
          onClose={() => setFormOpen(false)}
          onSaved={load}
          defaultType={complaintsOnly ? 'Complaint' : undefined}
          defaultCustomer={customer}
        />
      )}
      {selectedId && <FeedbackDetailDialog feedbackId={selectedId} onClose={() => setSelectedId(null)} onChanged={load} />}
    </Box>
  );
};

export default FeedbackManagement;
