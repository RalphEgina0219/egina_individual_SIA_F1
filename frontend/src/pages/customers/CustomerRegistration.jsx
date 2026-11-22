import React, { useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Card,
  CardContent,
  Checkbox,
  CircularProgress,
  Divider,
  FormControlLabel,
  Grid,
  MenuItem,
  Step,
  StepLabel,
  Stepper,
  TextField,
  Typography,
} from '@mui/material';
import { CheckCircle, PersonAdd, Search } from '@mui/icons-material';
import { useNavigate } from 'react-router-dom';
import { customerService } from '../../services/api';
import { useNotify } from '../../context/NotifyContext';
import { useAuth } from '../../context/AuthContext';
import { useOptions } from '../../hooks/useOptions';
import { apiError } from '../../utils/helpers';
import CustomerForm from '../../components/customers/CustomerForm';
import { emptyCustomer, validateCustomer } from '../../utils/customerValidation';
import DuplicateList from '../../components/customers/DuplicateList';
import { InfoRow, PageHeader, StatusChip } from '../../components/common/UI';

const steps = ['Customer information', 'Duplicate check', 'Verification & activation', 'Account created'];

const CustomerRegistration = () => {
  const navigate = useNavigate();
  const notify = useNotify();
  const { can } = useAuth();
  const options = useOptions();

  const [values, setValues] = useState(emptyCustomer);
  const [errors, setErrors] = useState({});
  const [workflow, setWorkflow] = useState({
    identity_verified: false,
    verification_method: '',
    activate: false,
    enroll_loyalty: false,
  });
  const [duplicates, setDuplicates] = useState([]);
  const [duplicatesChecked, setDuplicatesChecked] = useState(false);
  const [requiresConfirmation, setRequiresConfirmation] = useState(false);
  const [checking, setChecking] = useState(false);
  const [saving, setSaving] = useState(false);
  const [created, setCreated] = useState(null);

  const handleChange = (name, value) => {
    setValues((prev) => ({ ...prev, [name]: value }));
    setErrors((prev) => ({ ...prev, [name]: undefined }));
    if (['full_name', 'phone', 'email', 'birthday'].includes(name)) {
      setDuplicatesChecked(false);
      setRequiresConfirmation(false);
    }
  };

  const runDuplicateCheck = async () => {
    if (!values.phone && !values.email && !values.full_name) return;
    setChecking(true);
    try {
      const res = await customerService.checkDuplicates({
        full_name: values.full_name || null,
        phone: values.phone || null,
        email: values.email || null,
        birthday: values.birthday || null,
      });
      setDuplicates(res.data || []);
      setDuplicatesChecked(true);
    } catch (error) {
      notify(apiError(error, 'Duplicate check failed').message, 'error');
    } finally {
      setChecking(false);
    }
  };

  const handleWorkflow = (name, value) => {
    setWorkflow((prev) => {
      const next = { ...prev, [name]: value };
      if (!next.identity_verified) {
        next.activate = false;
        next.enroll_loyalty = false;
      }
      if (!next.activate) next.enroll_loyalty = false;
      return next;
    });
  };

  const handleSubmit = async (confirmNotDuplicate = false) => {
    const clientErrors = validateCustomer(values);
    if (workflow.identity_verified && !workflow.verification_method) {
      clientErrors.verification_method = 'Select how the identity was verified.';
    }
    setErrors(clientErrors);
    if (Object.keys(clientErrors).length) {
      notify('Please correct the highlighted fields.', 'error');
      return;
    }

    setSaving(true);
    try {
      const payload = {
        ...values,
        email: values.email || null,
        birthday: values.birthday || null,
        preferred_branch_id: values.preferred_branch_id || null,
        identity_verified: workflow.identity_verified,
        verification_method: workflow.identity_verified ? workflow.verification_method : null,
        activate: workflow.activate,
        enroll_loyalty: workflow.enroll_loyalty,
        confirm_not_duplicate: confirmNotDuplicate,
      };
      const res = await customerService.create(payload);
      setCreated(res.data);
      notify(res.message || 'Customer registered successfully');
    } catch (error) {
      const err = apiError(error, 'Failed to register customer');
      if (err.status === 409) {
        setDuplicates(err.data?.duplicates || []);
        setDuplicatesChecked(true);
        setRequiresConfirmation(Boolean(err.data?.requires_confirmation));
      } else {
        setErrors((prev) => ({ ...prev, ...err.fieldErrors }));
      }
      notify(err.message, 'error');
    } finally {
      setSaving(false);
    }
  };

  const reset = () => {
    setValues(emptyCustomer);
    setWorkflow({ identity_verified: false, verification_method: '', activate: false, enroll_loyalty: false });
    setDuplicates([]);
    setDuplicatesChecked(false);
    setRequiresConfirmation(false);
    setCreated(null);
    setErrors({});
  };

  const activeStep = created ? 3 : workflow.identity_verified ? 2 : duplicatesChecked ? 2 : values.full_name && values.phone ? 1 : 0;
  const blocking = duplicates.some((d) => d.blocking);

  if (created) {
    return (
      <Box>
        <PageHeader title="Customer Registration" subtitle="Register new ShopLink customer accounts" />
        <Card sx={{ maxWidth: 760 }}>
          <CardContent>
            <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, mb: 2 }}>
              <CheckCircle color="success" />
              <Typography variant="h6">Customer account created</Typography>
            </Box>
            <Grid container spacing={2}>
              <Grid size={{ xs: 12, sm: 6 }}><InfoRow label="Customer ID" value={<strong>{created.customer_code}</strong>} /></Grid>
              <Grid size={{ xs: 12, sm: 6 }}><InfoRow label="Membership Number" value={<strong>{created.membership_number}</strong>} /></Grid>
              <Grid size={{ xs: 12, sm: 6 }}><InfoRow label="Name" value={created.full_name} /></Grid>
              <Grid size={{ xs: 12, sm: 6 }}><InfoRow label="Status" value={<StatusChip status={created.status} />} /></Grid>
              <Grid size={{ xs: 12, sm: 6 }}><InfoRow label="Membership Level" value={created.membership?.level?.name} /></Grid>
              <Grid size={{ xs: 12, sm: 6 }}>
                <InfoRow label="Loyalty Program" value={created.membership?.is_loyalty_member ? 'Enrolled' : 'Not enrolled'} />
              </Grid>
            </Grid>
            {created.status === 'Pending Verification' && (
              <Alert severity="info" sx={{ mt: 1 }}>
                The account is pending verification. Verify the customer identity from the profile to activate it.
              </Alert>
            )}
            <Alert severity="success" variant="outlined" sx={{ mt: 2 }}>
              A registration notification was recorded for this customer (delivered by e-mail when an address is on file).
            </Alert>
            <Box sx={{ display: 'flex', gap: 1, mt: 3, flexWrap: 'wrap' }}>
              <Button variant="contained" onClick={() => navigate(`/customers/${created.id}`)} sx={{ backgroundColor: '#1a237e' }}>
                View Profile
              </Button>
              <Button variant="outlined" startIcon={<PersonAdd />} onClick={reset}>Register Another</Button>
            </Box>
          </CardContent>
        </Card>
      </Box>
    );
  }

  return (
    <Box>
      <PageHeader title="Customer Registration" subtitle="Staff-assisted registration with duplicate checking, identity verification and activation" />

      <Card sx={{ mb: 3, display: { xs: 'none', md: 'block' } }}>
        <CardContent>
          <Stepper activeStep={activeStep} alternativeLabel>
            {steps.map((label) => <Step key={label}><StepLabel>{label}</StepLabel></Step>)}
          </Stepper>
        </CardContent>
      </Card>

      <Card sx={{ mb: 3 }}>
        <CardContent>
          <Typography variant="h6" gutterBottom>Customer Information</Typography>
          <CustomerForm
            values={values}
            onChange={handleChange}
            errors={errors}
            onBlurField={(name) => ['phone', 'email'].includes(name) && runDuplicateCheck()}
          />
          <Box sx={{ mt: 2, display: 'flex', alignItems: 'center', gap: 2, flexWrap: 'wrap' }}>
            <Button
              variant="outlined"
              startIcon={checking ? <CircularProgress size={16} /> : <Search />}
              onClick={runDuplicateCheck}
              disabled={checking || (!values.phone && !values.email && !values.full_name)}
            >
              Check for Duplicates
            </Button>
            {duplicatesChecked && !duplicates.length && (
              <Typography variant="body2" color="success.main">No existing account matches these details.</Typography>
            )}
          </Box>
          <Box sx={{ mt: 2 }}>
            <DuplicateList duplicates={duplicates} />
          </Box>
        </CardContent>
      </Card>

      <Card sx={{ mb: 3 }}>
        <CardContent>
          <Typography variant="h6" gutterBottom>Verification & Activation</Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
            Verify the customer identity when required. Unverified accounts are saved as Pending Verification.
          </Typography>
          <Grid container spacing={2} alignItems="center">
            <Grid size={{ xs: 12, md: 4 }}>
              <FormControlLabel
                control={<Checkbox checked={workflow.identity_verified} onChange={(e) => handleWorkflow('identity_verified', e.target.checked)} />}
                label="Customer identity verified"
              />
            </Grid>
            <Grid size={{ xs: 12, md: 8 }}>
              <TextField
                select
                fullWidth
                size="small"
                label="Verification Method"
                value={workflow.verification_method}
                onChange={(e) => handleWorkflow('verification_method', e.target.value)}
                disabled={!workflow.identity_verified}
                error={Boolean(errors.verification_method)}
                helperText={errors.verification_method}
              >
                {options.verification_methods.map((m) => <MenuItem key={m} value={m}>{m}</MenuItem>)}
              </TextField>
            </Grid>
            <Grid size={{ xs: 12, md: 4 }}>
              <FormControlLabel
                control={<Checkbox checked={workflow.activate} disabled={!workflow.identity_verified} onChange={(e) => handleWorkflow('activate', e.target.checked)} />}
                label="Activate account now"
              />
            </Grid>
            {can('loyalty.enroll') && (
              <Grid size={{ xs: 12, md: 8 }}>
                <FormControlLabel
                  control={<Checkbox checked={workflow.enroll_loyalty} disabled={!workflow.activate} onChange={(e) => handleWorkflow('enroll_loyalty', e.target.checked)} />}
                  label="Enroll in the loyalty program"
                />
              </Grid>
            )}
          </Grid>
        </CardContent>
      </Card>

      <Divider sx={{ mb: 2 }} />
      <Box sx={{ display: 'flex', gap: 1, justifyContent: 'flex-end', flexWrap: 'wrap' }}>
        <Button onClick={reset} disabled={saving}>Clear</Button>
        {requiresConfirmation && !blocking && (
          <Button variant="outlined" color="warning" onClick={() => handleSubmit(true)} disabled={saving}>
            Not a Duplicate - Create Anyway
          </Button>
        )}
        <Button
          variant="contained"
          onClick={() => handleSubmit(false)}
          disabled={saving || blocking}
          startIcon={saving ? <CircularProgress size={16} color="inherit" /> : <PersonAdd />}
          sx={{ backgroundColor: '#1a237e' }}
        >
          Create Customer Account
        </Button>
      </Box>
    </Box>
  );
};

export default CustomerRegistration;
