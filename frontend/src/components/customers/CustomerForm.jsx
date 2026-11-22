import React from 'react';
import { Grid, MenuItem, TextField } from '@mui/material';
import { useOptions } from '../../hooks/useOptions';

const CustomerForm = ({ values, onChange, errors = {}, mode = 'create', onBlurField }) => {
  const options = useOptions();

  const field = (name) => ({
    name,
    value: values[name] ?? '',
    onChange: (e) => onChange(name, e.target.value),
    onBlur: onBlurField ? () => onBlurField(name) : undefined,
    error: Boolean(errors[name]),
    helperText: errors[name],
    fullWidth: true,
  });

  return (
    <Grid container spacing={2}>
      <Grid size={{ xs: 12, md: 6 }}>
        <TextField label="Full Name" required {...field('full_name')} />
      </Grid>
      <Grid size={{ xs: 12, sm: 6, md: 3 }}>
        <TextField label="Contact Number" required placeholder="09XX XXX XXXX" {...field('phone')} />
      </Grid>
      <Grid size={{ xs: 12, sm: 6, md: 3 }}>
        <TextField label="Birthday" type="date" slotProps={{ inputLabel: { shrink: true } }} {...field('birthday')} />
      </Grid>
      <Grid size={{ xs: 12, md: 6 }}>
        <TextField label="E-mail Address" type="email" {...field('email')} />
      </Grid>
      <Grid size={{ xs: 12, md: 6 }}>
        <TextField label="Address" {...field('address')} />
      </Grid>
      <Grid size={{ xs: 12, sm: 6, md: 3 }}>
        <TextField select label="Preferred Branch" {...field('preferred_branch_id')}>
          <MenuItem value="">No preference</MenuItem>
          {options.branches.map((b) => <MenuItem key={b.id} value={b.id}>{b.name}</MenuItem>)}
        </TextField>
      </Grid>
      <Grid size={{ xs: 12, sm: 6, md: 3 }}>
        <TextField select label="Preferred Contact Method" required {...field('preferred_contact_method')}>
          {options.contact_methods.map((m) => <MenuItem key={m} value={m}>{m}</MenuItem>)}
        </TextField>
      </Grid>
      <Grid size={{ xs: 12, sm: 6, md: 3 }}>
        <TextField select label="Customer Type" required {...field('customer_type')}>
          {options.customer_types.map((t) => <MenuItem key={t} value={t}>{t}</MenuItem>)}
        </TextField>
      </Grid>
      {mode === 'create' && (
        <Grid size={{ xs: 12, sm: 6, md: 3 }}>
          <TextField select label="Registration Channel" required {...field('registration_channel')}>
            {options.registration_channels.map((c) => <MenuItem key={c} value={c}>{c}</MenuItem>)}
          </TextField>
        </Grid>
      )}
      <Grid size={12}>
        <TextField label="Notes" multiline minRows={2} {...field('notes')} />
      </Grid>
    </Grid>
  );
};

export default CustomerForm;
