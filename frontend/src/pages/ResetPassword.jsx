import React, { useState } from 'react';
import { Alert, Box, Button, CircularProgress, Container, Paper, TextField, Typography } from '@mui/material';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { authService } from '../services/api';
import { apiError } from '../utils/helpers';

const ResetPassword = () => {
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [done, setDone] = useState('');

  const token = params.get('token');
  const email = params.get('email');

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    if (password !== confirmation) {
      setError('Passwords do not match.');
      return;
    }
    setLoading(true);
    try {
      const res = await authService.resetPassword({ token, email, password, password_confirmation: confirmation });
      setDone(res.message);
    } catch (err) {
      const e2 = apiError(err, 'Password reset failed');
      setError(Object.values(e2.fieldErrors)[0] || e2.message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <Container maxWidth="sm">
      <Box sx={{ minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
        <Paper elevation={3} sx={{ p: 4, width: '100%', maxWidth: 400 }}>
          <Typography variant="h5" align="center" gutterBottom sx={{ color: '#1a237e' }}>Set a New Password</Typography>
          {!token || !email ? (
            <Alert severity="error">This reset link is invalid. Request a new one from the sign-in page.</Alert>
          ) : done ? (
            <>
              <Alert severity="success" sx={{ mb: 2 }}>{done}</Alert>
              <Button fullWidth variant="contained" onClick={() => navigate('/login')}>Go to Sign In</Button>
            </>
          ) : (
            <form onSubmit={handleSubmit}>
              <Typography variant="body2" color="text.secondary" align="center">{email}</Typography>
              {error && <Alert severity="error" sx={{ mt: 2 }}>{error}</Alert>}
              <TextField fullWidth margin="normal" type="password" label="New password (min. 8 characters)" value={password} onChange={(e) => setPassword(e.target.value)} required />
              <TextField fullWidth margin="normal" type="password" label="Confirm new password" value={confirmation} onChange={(e) => setConfirmation(e.target.value)} required />
              <Button type="submit" fullWidth variant="contained" sx={{ mt: 2, backgroundColor: '#1a237e' }} disabled={loading || password.length < 8}>
                {loading ? <CircularProgress size={24} /> : 'Reset Password'}
              </Button>
            </form>
          )}
        </Paper>
      </Box>
    </Container>
  );
};

export default ResetPassword;
