import React, { useState } from 'react';
import { Box, Button, Card, CardContent, Grid, MenuItem, TextField, Typography } from '@mui/material';
import { Calculate, Tune } from '@mui/icons-material';
import { loyaltyService } from '../../services/api';
import { useAuth } from '../../context/AuthContext';
import { useNotify } from '../../context/NotifyContext';
import { useOptions } from '../../hooks/useOptions';
import { apiError } from '../../utils/helpers';
import { PageHeader } from '../../components/common/UI';
import CustomerPicker from '../../components/common/CustomerPicker';
import LoyaltyLedger, { AdjustPointsDialog } from '../../components/customers/LoyaltyLedger';

const LoyaltyPoints = () => {
  const { can } = useAuth();
  const notify = useNotify();
  const options = useOptions();
  const [refreshKey, setRefreshKey] = useState(0);
  const [adjustCustomer, setAdjustCustomer] = useState(null);
  const [adjustOpen, setAdjustOpen] = useState(false);
  const [calc, setCalc] = useState({ amount: '', membership_level_id: '' });
  const [calcResult, setCalcResult] = useState(null);

  const calculate = async () => {
    try {
      const res = await loyaltyService.calculate({ amount: calc.amount, membership_level_id: calc.membership_level_id || undefined });
      setCalcResult(res.data);
    } catch (error) {
      notify(apiError(error).message, 'error');
    }
  };

  return (
    <Box>
      <PageHeader title="Loyalty Points" subtitle="Points balances and the complete earning, redemption and adjustment history" />

      <Grid container spacing={2} sx={{ mb: 3 }}>
        {can('loyalty.adjust') && (
          <Grid size={{ xs: 12, md: 6 }}>
            <Card sx={{ height: '100%' }}>
              <CardContent>
                <Typography variant="h6" gutterBottom>Authorized Points Adjustment</Typography>
                <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
                  Credit or deduct points with a recorded reason (e.g. service recovery, correction of a discrepancy).
                </Typography>
                <Box sx={{ display: 'flex', gap: 1, alignItems: 'flex-start', flexDirection: { xs: 'column', sm: 'row' } }}>
                  <Box sx={{ flexGrow: 1, width: '100%' }}>
                    <CustomerPicker value={adjustCustomer} onChange={setAdjustCustomer} size="small" />
                  </Box>
                  <Button
                    variant="contained" startIcon={<Tune />} disabled={!adjustCustomer?.is_loyalty_member}
                    onClick={() => setAdjustOpen(true)} sx={{ backgroundColor: '#1a237e', whiteSpace: 'nowrap' }}
                  >
                    Adjust
                  </Button>
                </Box>
                {adjustCustomer && !adjustCustomer.is_loyalty_member && (
                  <Typography variant="caption" color="error">This customer is not enrolled in the loyalty program.</Typography>
                )}
              </CardContent>
            </Card>
          </Grid>
        )}
        <Grid size={{ xs: 12, md: can('loyalty.adjust') ? 6 : 12 }}>
          <Card sx={{ height: '100%' }}>
            <CardContent>
              <Typography variant="h6" gutterBottom>Points Calculator</Typography>
              <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>Preview points under the current earning rules.</Typography>
              <Grid container spacing={1} alignItems="center">
                <Grid size={{ xs: 12, sm: 4 }}>
                  <TextField fullWidth size="small" type="number" label="Purchase amount (PHP)" value={calc.amount} onChange={(e) => setCalc({ ...calc, amount: e.target.value })} />
                </Grid>
                <Grid size={{ xs: 12, sm: 4 }}>
                  <TextField select fullWidth size="small" label="Level" value={calc.membership_level_id} onChange={(e) => setCalc({ ...calc, membership_level_id: e.target.value })}>
                    <MenuItem value="">Base rate</MenuItem>
                    {options.membership_levels.map((l) => <MenuItem key={l.id} value={l.id}>{l.name} (x{l.points_multiplier})</MenuItem>)}
                  </TextField>
                </Grid>
                <Grid size={{ xs: 12, sm: 4 }}>
                  <Button fullWidth variant="outlined" startIcon={<Calculate />} disabled={calc.amount === ''} onClick={calculate}>Calculate</Button>
                </Grid>
              </Grid>
              {calcResult && (
                <Typography sx={{ mt: 2 }}>
                  {calcResult.is_configured
                    ? <>Earns <strong>{calcResult.points}</strong> point(s).</>
                    : 'Points earning is not configured - no points would be awarded.'}
                </Typography>
              )}
            </CardContent>
          </Card>
        </Grid>
      </Grid>

      <LoyaltyLedger refreshKey={refreshKey} />

      {adjustOpen && adjustCustomer && (
        <AdjustPointsDialog
          customer={adjustCustomer}
          onClose={() => setAdjustOpen(false)}
          onDone={() => setRefreshKey((k) => k + 1)}
        />
      )}
    </Box>
  );
};

export default LoyaltyPoints;
