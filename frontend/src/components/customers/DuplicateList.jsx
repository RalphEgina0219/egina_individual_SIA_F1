import React from 'react';
import { Alert, AlertTitle, Box, Chip, Link, List, ListItem, ListItemText } from '@mui/material';
import { Link as RouterLink } from 'react-router-dom';
import { StatusChip } from '../common/UI';

/**
 * Possible duplicate accounts returned by the backend duplicate detection.
 */
const DuplicateList = ({ duplicates, title, severity }) => {
  if (!duplicates?.length) return null;
  const blocking = duplicates.some((d) => d.blocking);

  return (
    <Alert severity={severity || (blocking ? 'error' : 'warning')} sx={{ mb: 2 }}>
      <AlertTitle>
        {title || (blocking
          ? 'An account with the same contact details already exists'
          : 'Possible duplicate customer accounts')}
      </AlertTitle>
      <List dense disablePadding>
        {duplicates.map((d) => (
          <ListItem key={d.id} disableGutters>
            <ListItemText
              primary={
                <Box sx={{ display: 'flex', gap: 1, alignItems: 'center', flexWrap: 'wrap' }}>
                  <Link component={RouterLink} to={`/customers/${d.id}`} target="_blank">
                    {d.full_name} ({d.customer_code})
                  </Link>
                  <StatusChip status={d.status} />
                  {d.reasons.map((r) => <Chip key={r} size="small" variant="outlined" label={r} />)}
                </Box>
              }
              secondary={[d.phone, d.email].filter(Boolean).join(' · ')}
            />
          </ListItem>
        ))}
      </List>
    </Alert>
  );
};

export default DuplicateList;
