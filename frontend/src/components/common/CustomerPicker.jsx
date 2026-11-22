import React, { useEffect, useState } from 'react';
import { Autocomplete, Box, Chip, CircularProgress, TextField, Typography } from '@mui/material';
import { customerService } from '../../services/api';

/**
 * Search customers by name, phone, e-mail, Customer ID or Membership Number.
 * `value` is the selected lookup record (or null).
 */
const CustomerPicker = ({ value, onChange, label = 'Customer', helperText, error, disabled, size, placeholder }) => {
  const [input, setInput] = useState('');
  const [options, setOptions] = useState([]);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    const term = input.trim();
    if (term.length < 2) return undefined;

    let active = true;
    const timer = setTimeout(() => {
      setLoading(true);
      customerService.lookup(term)
        .then((res) => active && setOptions(res.data || []))
        .catch(() => active && setOptions([]))
        .finally(() => active && setLoading(false));
    }, 300);

    return () => {
      active = false;
      clearTimeout(timer);
    };
  }, [input]);

  return (
    <Autocomplete
      value={value}
      onChange={(_, selected) => onChange(selected)}
      inputValue={input}
      onInputChange={(_, text) => setInput(text)}
      options={value && !options.some((o) => o.id === value.id) ? [value, ...options] : options}
      filterOptions={(x) => x}
      getOptionLabel={(option) => (option ? `${option.full_name} (${option.customer_code})` : '')}
      isOptionEqualToValue={(option, selected) => option.id === selected.id}
      loading={loading}
      disabled={disabled}
      size={size}
      noOptionsText={input.trim().length < 2 ? 'Type at least 2 characters' : 'No matching customer'}
      renderOption={(props, option) => {
        const { key, ...rest } = props;
        return (
          <Box component="li" key={key} {...rest} sx={{ display: 'flex', justifyContent: 'space-between', gap: 1 }}>
            <Box>
              <Typography variant="body2">{option.full_name}</Typography>
              <Typography variant="caption" color="text.secondary">
                {option.customer_code} · {option.phone}
              </Typography>
            </Box>
            <Box sx={{ textAlign: 'right' }}>
              <Chip size="small" label={option.status} color={option.can_transact ? 'success' : 'default'} />
              {option.is_loyalty_member && (
                <Typography variant="caption" sx={{ display: 'block' }}>{option.points_balance} pts</Typography>
              )}
            </Box>
          </Box>
        );
      }}
      renderInput={(params) => (
        <TextField
          {...params}
          label={label}
          placeholder={placeholder || 'Name, phone, Customer ID...'}
          helperText={helperText}
          error={error}
          slotProps={{
            input: {
              ...params.InputProps,
              endAdornment: (
                <>
                  {loading ? <CircularProgress color="inherit" size={18} /> : null}
                  {params.InputProps.endAdornment}
                </>
              ),
            },
          }}
        />
      )}
    />
  );
};

export default CustomerPicker;
