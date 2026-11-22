import React, { useCallback, useEffect, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Checkbox,
  Chip,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Divider,
  FormControlLabel,
  Grid,
  MenuItem,
  Paper,
  Rating,
  TextField,
  Typography,
} from '@mui/material';
import { feedbackService } from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { useNotify } from '../../context/NotifyContext';
import { useOptions } from '../../hooks/useOptions';
import { apiError, formatDateTime } from '../../utils/helpers';
import { InfoRow, StatusChip } from '../common/UI';
import CustomerPicker from '../common/CustomerPicker';

const emptyFeedback = {
  type: 'General Feedback',
  category: '',
  channel: 'In-store',
  rating: null,
  subject: '',
  message: '',
  branch_id: '',
  priority: 'Medium',
  contact_name: '',
  contact_info: '',
  received_at: '',
};

/**
 * Centralized recording of feedback received in-store, by website, e-mail,
 * phone or social media (entered by staff; channels are not auto-synced).
 */
export const FeedbackFormDialog = ({ open, onClose, onSaved, defaultCustomer = null, defaultType }) => {
  const notify = useNotify();
  const options = useOptions();
  const [customer, setCustomer] = useState(defaultCustomer);
  const [values, setValues] = useState({ ...emptyFeedback, type: defaultType || emptyFeedback.type });
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);

  const set = (name, value) => {
    setValues((prev) => ({ ...prev, [name]: value }));
    setErrors((prev) => ({ ...prev, [name]: undefined }));
  };

  const submit = async () => {
    setSaving(true);
    try {
      const payload = {
        ...values,
        customer_id: customer?.id || null,
        category: values.category || null,
        branch_id: values.branch_id || null,
        received_at: values.received_at || null,
        contact_name: customer ? null : values.contact_name || null,
        contact_info: customer ? null : values.contact_info || null,
      };
      const res = await feedbackService.create(payload);
      notify(`${res.message} (${res.data.reference_number})`);
      onSaved?.(res.data);
      onClose();
    } catch (error) {
      const err = apiError(error, 'Failed to record feedback');
      setErrors(err.fieldErrors);
      notify(err.message, 'error');
    } finally {
      setSaving(false);
    }
  };

  const isComplaint = options.complaint_types.includes(values.type);

  return (
    <Dialog open={open} onClose={saving ? undefined : onClose} maxWidth="md" fullWidth>
      <DialogTitle>Record Customer Feedback</DialogTitle>
      <DialogContent dividers>
        <Grid container spacing={2}>
          <Grid size={12}>
            <CustomerPicker
              value={customer}
              onChange={setCustomer}
              label="Customer (optional)"
              helperText={errors.customer_id || 'Leave empty for walk-in or anonymous feedback'}
              disabled={Boolean(defaultCustomer)}
            />
          </Grid>
          {!customer && (
            <>
              <Grid size={{ xs: 12, md: 6 }}>
                <TextField fullWidth label="Contact Name" required value={values.contact_name} onChange={(e) => set('contact_name', e.target.value)} error={Boolean(errors.contact_name)} helperText={errors.contact_name} />
              </Grid>
              <Grid size={{ xs: 12, md: 6 }}>
                <TextField fullWidth label="Contact Info (phone / e-mail)" value={values.contact_info} onChange={(e) => set('contact_info', e.target.value)} />
              </Grid>
            </>
          )}
          <Grid size={{ xs: 12, sm: 6, md: 3 }}>
            <TextField select fullWidth required label="Type" value={values.type} onChange={(e) => set('type', e.target.value)}>
              {options.feedback_types.map((t) => <MenuItem key={t} value={t}>{t}</MenuItem>)}
            </TextField>
          </Grid>
          <Grid size={{ xs: 12, sm: 6, md: 3 }}>
            <TextField select fullWidth label="Category" value={values.category} onChange={(e) => set('category', e.target.value)}>
              <MenuItem value="">Uncategorized</MenuItem>
              {options.feedback_categories.map((c) => <MenuItem key={c} value={c}>{c}</MenuItem>)}
            </TextField>
          </Grid>
          <Grid size={{ xs: 12, sm: 6, md: 3 }}>
            <TextField select fullWidth required label="Received via" value={values.channel} onChange={(e) => set('channel', e.target.value)}>
              {options.feedback_channels.map((c) => <MenuItem key={c} value={c}>{c}</MenuItem>)}
            </TextField>
          </Grid>
          <Grid size={{ xs: 12, sm: 6, md: 3 }}>
            <TextField select fullWidth label="Priority" value={values.priority} onChange={(e) => set('priority', e.target.value)}>
              {options.feedback_priorities.map((p) => <MenuItem key={p} value={p}>{p}</MenuItem>)}
            </TextField>
          </Grid>
          <Grid size={{ xs: 12, sm: 6, md: 4 }}>
            <TextField select fullWidth label="Branch" value={values.branch_id} onChange={(e) => set('branch_id', e.target.value)}>
              <MenuItem value="">Not specified</MenuItem>
              {options.branches.map((b) => <MenuItem key={b.id} value={b.id}>{b.name}</MenuItem>)}
            </TextField>
          </Grid>
          <Grid size={{ xs: 12, sm: 6, md: 4 }}>
            <TextField
              fullWidth type="datetime-local" label="Date received" value={values.received_at}
              onChange={(e) => set('received_at', e.target.value)} helperText={errors.received_at || 'Defaults to now'}
              error={Boolean(errors.received_at)} slotProps={{ inputLabel: { shrink: true } }}
            />
          </Grid>
          <Grid size={{ xs: 12, md: 4 }}>
            <Typography variant="caption" color="text.secondary">Rating</Typography>
            <Box><Rating value={values.rating} onChange={(_, v) => set('rating', v)} /></Box>
          </Grid>
          <Grid size={12}>
            <TextField fullWidth required label="Subject" value={values.subject} onChange={(e) => set('subject', e.target.value)} error={Boolean(errors.subject)} helperText={errors.subject} />
          </Grid>
          <Grid size={12}>
            <TextField fullWidth required multiline minRows={4} label="Details" value={values.message} onChange={(e) => set('message', e.target.value)} error={Boolean(errors.message)} helperText={errors.message} />
          </Grid>
        </Grid>
        {isComplaint && (
          <Alert severity="info" sx={{ mt: 2 }}>
            This will be tracked as a complaint: it must be assigned, resolved and closed through Complaint Tracking.
          </Alert>
        )}
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={saving}>Cancel</Button>
        <Button
          variant="contained" onClick={submit}
          disabled={saving || !values.subject.trim() || !values.message.trim() || (!customer && !values.contact_name.trim())}
          startIcon={saving ? <CircularProgress size={16} color="inherit" /> : null}
          sx={{ backgroundColor: '#1a237e' }}
        >
          Record Feedback
        </Button>
      </DialogActions>
    </Dialog>
  );
};

/**
 * Feedback / complaint detail: categorize, assign, investigate, resolve,
 * communicate and close.
 */
export const FeedbackDetailDialog = ({ feedbackId, onClose, onChanged }) => {
  const { can, user } = useAuth();
  const notify = useNotify();
  const options = useOptions();
  const [feedback, setFeedback] = useState(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState('');
  const [working, setWorking] = useState(false);
  const [assign, setAssign] = useState({ department: '', assigned_to: '', notes: '' });
  const [response, setResponse] = useState({ response_type: 'Investigation', message: '', communicated_via: '', send_email: false });
  const [statusChange, setStatusChange] = useState({ status: '', note: '', notify_customer: false });

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await feedbackService.getById(feedbackId);
      setFeedback(res.data);
    } catch (error) {
      setLoadError(apiError(error, 'Failed to load feedback').message);
    } finally {
      setLoading(false);
    }
  }, [feedbackId]);

  useEffect(() => {
    load();
  }, [load]);

  const run = async (action, reset) => {
    setWorking(true);
    try {
      const res = await action();
      notify(res.message);
      reset?.();
      await load();
      onChanged?.();
    } catch (error) {
      notify(apiError(error).message, 'error');
    } finally {
      setWorking(false);
    }
  };

  const closed = feedback?.status === 'Closed';
  const canRespond = can('feedback.respond') || feedback?.assigned_to === user?.id;

  return (
    <Dialog open onClose={working ? undefined : onClose} maxWidth="lg" fullWidth>
      <DialogTitle sx={{ display: 'flex', alignItems: 'center', gap: 1, flexWrap: 'wrap' }}>
        {feedback ? `${feedback.reference_number} - ${feedback.subject}` : 'Feedback'}
        {feedback && <StatusChip status={feedback.status} />}
        {feedback && <StatusChip status={feedback.priority} variant="outlined" />}
      </DialogTitle>
      <DialogContent dividers>
        {loadError ? (
          <Alert severity="error">{loadError}</Alert>
        ) : loading && !feedback ? (
          <Box sx={{ textAlign: 'center', py: 6 }}><CircularProgress /></Box>
        ) : (
          <Grid container spacing={3}>
            <Grid size={{ xs: 12, md: 5 }}>
              <Paper variant="outlined" sx={{ p: 2, mb: 2 }}>
                <Grid container spacing={1}>
                  <Grid size={6}><InfoRow label="Type" value={feedback.type} /></Grid>
                  <Grid size={6}><InfoRow label="Category" value={feedback.category || 'Uncategorized'} /></Grid>
                  <Grid size={6}><InfoRow label="Channel" value={feedback.channel} /></Grid>
                  <Grid size={6}><InfoRow label="Received" value={formatDateTime(feedback.received_at)} /></Grid>
                  <Grid size={6}>
                    <InfoRow label="Customer" value={feedback.customer ? `${feedback.customer.full_name} (${feedback.customer.customer_code})` : `${feedback.contact_name || 'Anonymous'} ${feedback.contact_info ? `· ${feedback.contact_info}` : ''}`} />
                  </Grid>
                  <Grid size={6}><InfoRow label="Branch" value={feedback.branch?.name} /></Grid>
                  <Grid size={6}><InfoRow label="Department" value={feedback.department || 'Not assigned'} /></Grid>
                  <Grid size={6}><InfoRow label="Assigned To" value={feedback.assignee?.name || '-'} /></Grid>
                  {feedback.rating && <Grid size={12}><Rating value={feedback.rating} readOnly size="small" /></Grid>}
                </Grid>
                <Divider sx={{ my: 1 }} />
                <Typography variant="body2" sx={{ whiteSpace: 'pre-wrap' }}>{feedback.message}</Typography>
                {feedback.resolution_summary && (
                  <Alert severity="success" sx={{ mt: 2 }}>
                    <strong>Resolution:</strong> {feedback.resolution_summary}
                  </Alert>
                )}
              </Paper>

              {can('feedback.respond') && !closed && (
                <Paper variant="outlined" sx={{ p: 2, mb: 2 }}>
                  <Typography variant="subtitle2" gutterBottom>Categorize</Typography>
                  <Grid container spacing={1}>
                    <Grid size={{ xs: 12, sm: 4 }}>
                      <TextField select fullWidth size="small" label="Type" value={feedback.type}
                        onChange={(e) => run(() => feedbackService.update(feedback.id, { type: e.target.value }))}>
                        {options.feedback_types.map((t) => <MenuItem key={t} value={t}>{t}</MenuItem>)}
                      </TextField>
                    </Grid>
                    <Grid size={{ xs: 12, sm: 4 }}>
                      <TextField select fullWidth size="small" label="Category" value={feedback.category || ''}
                        onChange={(e) => run(() => feedbackService.update(feedback.id, { category: e.target.value || null }))}>
                        <MenuItem value="">Uncategorized</MenuItem>
                        {options.feedback_categories.map((c) => <MenuItem key={c} value={c}>{c}</MenuItem>)}
                      </TextField>
                    </Grid>
                    <Grid size={{ xs: 12, sm: 4 }}>
                      <TextField select fullWidth size="small" label="Priority" value={feedback.priority}
                        onChange={(e) => run(() => feedbackService.update(feedback.id, { priority: e.target.value }))}>
                        {options.feedback_priorities.map((p) => <MenuItem key={p} value={p}>{p}</MenuItem>)}
                      </TextField>
                    </Grid>
                  </Grid>
                </Paper>
              )}

              {can('feedback.assign') && !closed && (
                <Paper variant="outlined" sx={{ p: 2, mb: 2 }}>
                  <Typography variant="subtitle2" gutterBottom>Assign to Department</Typography>
                  <Grid container spacing={1}>
                    <Grid size={{ xs: 12, sm: 6 }}>
                      <TextField select fullWidth size="small" label="Department" value={assign.department}
                        onChange={(e) => setAssign({ ...assign, department: e.target.value })}>
                        {options.departments.map((d) => <MenuItem key={d} value={d}>{d}</MenuItem>)}
                      </TextField>
                    </Grid>
                    <Grid size={{ xs: 12, sm: 6 }}>
                      <TextField select fullWidth size="small" label="Responsible staff" value={assign.assigned_to}
                        onChange={(e) => setAssign({ ...assign, assigned_to: e.target.value })}>
                        <MenuItem value="">Department queue</MenuItem>
                        {options.staff.map((s) => <MenuItem key={s.id} value={s.id}>{s.name} ({s.role})</MenuItem>)}
                      </TextField>
                    </Grid>
                    <Grid size={12}>
                      <TextField fullWidth size="small" label="Notes" value={assign.notes} onChange={(e) => setAssign({ ...assign, notes: e.target.value })} />
                    </Grid>
                  </Grid>
                  <Button
                    sx={{ mt: 1 }} size="small" variant="outlined" disabled={working || !assign.department}
                    onClick={() => run(
                      () => feedbackService.assign(feedback.id, { ...assign, assigned_to: assign.assigned_to || null }),
                      () => setAssign({ department: '', assigned_to: '', notes: '' })
                    )}
                  >
                    Assign
                  </Button>
                </Paper>
              )}
            </Grid>

            <Grid size={{ xs: 12, md: 7 }}>
              {canRespond && !closed && (
                <Paper variant="outlined" sx={{ p: 2, mb: 2 }}>
                  <Typography variant="subtitle2" gutterBottom>Record Response</Typography>
                  <Grid container spacing={1}>
                    <Grid size={{ xs: 12, sm: 6 }}>
                      <TextField select fullWidth size="small" label="Response type" value={response.response_type}
                        onChange={(e) => setResponse({ ...response, response_type: e.target.value })}>
                        {options.response_types.filter((t) => t !== 'Status Update').map((t) => <MenuItem key={t} value={t}>{t}</MenuItem>)}
                      </TextField>
                    </Grid>
                    {response.response_type === 'Customer Communication' && (
                      <Grid size={{ xs: 12, sm: 6 }}>
                        <TextField select fullWidth size="small" label="Communicated via" value={response.communicated_via}
                          onChange={(e) => setResponse({ ...response, communicated_via: e.target.value })}>
                          {['Email', 'SMS', 'Phone Call', 'In-store'].map((c) => <MenuItem key={c} value={c}>{c}</MenuItem>)}
                        </TextField>
                      </Grid>
                    )}
                    <Grid size={12}>
                      <TextField fullWidth multiline minRows={3} size="small" label="Message"
                        value={response.message} onChange={(e) => setResponse({ ...response, message: e.target.value })} />
                    </Grid>
                  </Grid>
                  {response.response_type === 'Resolution' && (
                    <Typography variant="caption" color="text.secondary">Recording a resolution marks the feedback as Resolved.</Typography>
                  )}
                  <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, mt: 1, flexWrap: 'wrap' }}>
                    {feedback.customer && response.response_type !== 'Customer Communication' && (
                      <FormControlLabel
                        control={<Checkbox size="small" checked={response.send_email} onChange={(e) => setResponse({ ...response, send_email: e.target.checked })} />}
                        label="E-mail this update to the customer"
                      />
                    )}
                    <Button
                      size="small" variant="contained" sx={{ backgroundColor: '#1a237e' }}
                      disabled={working || !response.message.trim() || (response.response_type === 'Customer Communication' && !response.communicated_via)}
                      onClick={() => run(
                        () => feedbackService.respond(feedback.id, { ...response, communicated_via: response.communicated_via || null }),
                        () => setResponse({ response_type: 'Investigation', message: '', communicated_via: '', send_email: false })
                      )}
                    >
                      Save Response
                    </Button>
                  </Box>
                </Paper>
              )}

              {(canRespond && !closed) || (closed && can('feedback.assign')) ? (
                <Paper variant="outlined" sx={{ p: 2, mb: 2 }}>
                  <Typography variant="subtitle2" gutterBottom>{closed ? 'Reopen' : 'Update Status'}</Typography>
                  <Grid container spacing={1} alignItems="center">
                    <Grid size={{ xs: 12, sm: 4 }}>
                      <TextField select fullWidth size="small" label="New status" value={statusChange.status}
                        onChange={(e) => setStatusChange({ ...statusChange, status: e.target.value })}>
                        {options.feedback_statuses.filter((s) => s !== feedback.status).map((s) => <MenuItem key={s} value={s}>{s}</MenuItem>)}
                      </TextField>
                    </Grid>
                    <Grid size={{ xs: 12, sm: 8 }}>
                      <TextField fullWidth size="small" label="Note" value={statusChange.note} onChange={(e) => setStatusChange({ ...statusChange, note: e.target.value })} />
                    </Grid>
                  </Grid>
                  <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, mt: 1, flexWrap: 'wrap' }}>
                    {feedback.customer && (
                      <FormControlLabel
                        control={<Checkbox size="small" checked={statusChange.notify_customer} onChange={(e) => setStatusChange({ ...statusChange, notify_customer: e.target.checked })} />}
                        label="Notify customer"
                      />
                    )}
                    <Button
                      size="small" variant="outlined" disabled={working || !statusChange.status}
                      onClick={() => run(
                        () => feedbackService.changeStatus(feedback.id, { ...statusChange, note: statusChange.note || null }),
                        () => setStatusChange({ status: '', note: '', notify_customer: false })
                      )}
                    >
                      Update Status
                    </Button>
                  </Box>
                </Paper>
              ) : null}

              <Typography variant="subtitle2" gutterBottom>Response History</Typography>
              <Box sx={{ maxHeight: 380, overflow: 'auto' }}>
                {feedback.responses.map((r) => (
                  <Paper key={r.id} variant="outlined" sx={{ p: 1.5, mb: 1 }}>
                    <Box sx={{ display: 'flex', justifyContent: 'space-between', gap: 1, flexWrap: 'wrap' }}>
                      <Box sx={{ display: 'flex', gap: 1, alignItems: 'center' }}>
                        <Chip size="small" label={r.response_type} />
                        {r.status_to && <Typography variant="caption">{r.status_from || '-'} → {r.status_to}</Typography>}
                        {r.communicated_via && <Typography variant="caption">via {r.communicated_via}</Typography>}
                      </Box>
                      <Typography variant="caption" color="text.secondary">
                        {r.user?.name || 'System'} · {formatDateTime(r.created_at)}
                      </Typography>
                    </Box>
                    <Typography variant="body2" sx={{ mt: 0.5, whiteSpace: 'pre-wrap' }}>{r.message}</Typography>
                  </Paper>
                ))}
              </Box>
              {feedback.assignments.length > 0 && (
                <>
                  <Typography variant="subtitle2" sx={{ mt: 2 }} gutterBottom>Assignment History</Typography>
                  {feedback.assignments.map((a) => (
                    <Typography key={a.id} variant="body2" color={a.is_current ? 'text.primary' : 'text.secondary'}>
                      {formatDateTime(a.assigned_at)} - {a.department}{a.assignee ? ` / ${a.assignee.name}` : ''} (by {a.assigner?.name || 'System'}){a.is_current ? ' · current' : ''}
                    </Typography>
                  ))}
                </>
              )}
            </Grid>
          </Grid>
        )}
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>Close</Button>
      </DialogActions>
    </Dialog>
  );
};
