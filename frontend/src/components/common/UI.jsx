import React from 'react';
import {
  Box,
  Button,
  Card,
  CardContent,
  Chip,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogContentText,
  DialogTitle,
  TableCell,
  TableRow,
  Typography,
} from '@mui/material';
import { Inbox as InboxIcon } from '@mui/icons-material';

export const BRAND = '#1a237e';

export const PageHeader = ({ title, subtitle, actions }) => (
  <Box
    sx={{
      display: 'flex',
      justifyContent: 'space-between',
      alignItems: { xs: 'flex-start', sm: 'center' },
      flexDirection: { xs: 'column', sm: 'row' },
      gap: 2,
      mb: 3,
    }}
  >
    <Box>
      <Typography variant="h4" sx={{ color: BRAND, fontSize: { xs: '1.6rem', md: '2.125rem' } }}>
        {title}
      </Typography>
      {subtitle && (
        <Typography variant="body2" color="text.secondary">
          {subtitle}
        </Typography>
      )}
    </Box>
    {actions && <Box sx={{ display: 'flex', gap: 1, flexWrap: 'wrap' }}>{actions}</Box>}
  </Box>
);

export const StatCard = ({ label, value, icon, color = BRAND, hint, onClick }) => (
  <Card
    sx={{ height: '100%', cursor: onClick ? 'pointer' : 'default', '&:hover': onClick ? { boxShadow: 4 } : {} }}
    onClick={onClick}
  >
    <CardContent sx={{ display: 'flex', alignItems: 'center', gap: 2 }}>
      {icon && (
        <Box sx={{ bgcolor: `${color}1a`, color, borderRadius: 2, p: 1.25, display: 'flex' }}>
          {icon}
        </Box>
      )}
      <Box sx={{ minWidth: 0 }}>
        <Typography variant="body2" color="text.secondary" noWrap>{label}</Typography>
        <Typography variant="h5" sx={{ fontWeight: 600 }}>{value}</Typography>
        {hint && <Typography variant="caption" color="text.secondary">{hint}</Typography>}
      </Box>
    </CardContent>
  </Card>
);

const STATUS_COLORS = {
  // Customer statuses
  Active: 'success',
  Verified: 'info',
  VIP: 'secondary',
  'Pending Verification': 'warning',
  Inactive: 'default',
  Suspended: 'warning',
  Blocked: 'error',
  Archived: 'default',
  // Feedback statuses
  Pending: 'warning',
  'Under Review': 'info',
  Assigned: 'primary',
  'In Progress': 'info',
  Resolved: 'success',
  Closed: 'default',
  // Orders
  Processing: 'info',
  Shipped: 'primary',
  Delivered: 'success',
  // Redemptions / notifications
  Completed: 'success',
  Cancelled: 'error',
  Sent: 'success',
  Skipped: 'default',
  Failed: 'error',
  // Priorities
  Urgent: 'error',
  High: 'warning',
  Medium: 'info',
  Low: 'default',
};

export const StatusChip = ({ status, size = 'small', variant }) => (
  <Chip label={status || '-'} color={STATUS_COLORS[status] || 'default'} size={size} variant={variant} />
);

/**
 * Loading / empty row for tables.
 */
export const TableStateRow = ({ colSpan, loading, empty, message = 'No records found' }) => {
  if (loading) {
    return (
      <TableRow>
        <TableCell colSpan={colSpan} align="center" sx={{ py: 5 }}>
          <CircularProgress size={28} />
        </TableCell>
      </TableRow>
    );
  }
  if (empty) {
    return (
      <TableRow>
        <TableCell colSpan={colSpan} align="center" sx={{ py: 5, color: 'text.secondary' }}>
          <InboxIcon sx={{ fontSize: 40, opacity: 0.4, display: 'block', mx: 'auto', mb: 1 }} />
          {message}
        </TableCell>
      </TableRow>
    );
  }
  return null;
};

export const ConfirmDialog = ({
  open,
  title,
  message,
  confirmText = 'Confirm',
  color = 'primary',
  loading = false,
  disabled = false,
  onConfirm,
  onClose,
  children,
}) => (
  <Dialog open={open} onClose={loading ? undefined : onClose} maxWidth="sm" fullWidth>
    <DialogTitle>{title}</DialogTitle>
    <DialogContent>
      {message && <DialogContentText sx={{ mb: children ? 2 : 0 }}>{message}</DialogContentText>}
      {children}
    </DialogContent>
    <DialogActions>
      <Button onClick={onClose} disabled={loading}>Cancel</Button>
      <Button
        onClick={onConfirm}
        variant="contained"
        color={color}
        disabled={loading || disabled}
        startIcon={loading ? <CircularProgress size={16} color="inherit" /> : null}
      >
        {confirmText}
      </Button>
    </DialogActions>
  </Dialog>
);

export const InfoRow = ({ label, value }) => (
  <Box sx={{ mb: 1.5 }}>
    <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{label}</Typography>
    <Typography variant="body2" component="div">{value ?? '-'}</Typography>
  </Box>
);
