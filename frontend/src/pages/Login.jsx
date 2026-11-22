import React, { useState } from 'react';
import {
  Container,
  Paper,
  TextField,
  Button,
  Typography,
  Box,
  Alert,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Link,
} from '@mui/material';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { authService } from '../services/api';

const Login = () => {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [forgotOpen, setForgotOpen] = useState(false);
  const [forgotEmail, setForgotEmail] = useState('');
  const [forgotMessage, setForgotMessage] = useState('');
  const [forgotLoading, setForgotLoading] = useState(false);

  const { login } = useAuth();
  const navigate = useNavigate();

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    setLoading(true);

    const result = await login(email, password);

    if (result.success) {
      navigate('/');
    } else {
      setError(result.message);
    }

    setLoading(false);
  };

  const handleForgot = async () => {
    setForgotLoading(true);
    try {
      const res = await authService.forgotPassword(forgotEmail);
      setForgotMessage(res.message);
    } catch (err) {
      setForgotMessage(err.response?.data?.message || 'Unable to send reset link. Try again later.');
    } finally {
      setForgotLoading(false);
    }
  };

  return (
    <Container maxWidth="sm">
      <Box
        sx={{
          minHeight: '100vh',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
        }}
      >
        <Paper
          elevation={3}
          sx={{
            p: 4,
            width: '100%',
            maxWidth: 400,
          }}
        >
          <Typography variant="h4" align="center" gutterBottom sx={{ mb: 3, color: '#1a237e' }}>
            ShopLink Supermarket
          </Typography>

          <Typography variant="h5" align="center" gutterBottom sx={{ mb: 3 }}>
            Sign In
          </Typography>

          {error && (
            <Alert severity="error" sx={{ mb: 2 }}>
              {error}
            </Alert>
          )}

          <form onSubmit={handleSubmit}>
            <TextField
              fullWidth
              label="Email"
              variant="outlined"
              margin="normal"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
              type="email"
            />

            <TextField
              fullWidth
              label="Password"
              variant="outlined"
              margin="normal"
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
            />

            <Button
              type="submit"
              fullWidth
              variant="contained"
              size="large"
              sx={{ mt: 3, mb: 2, backgroundColor: '#1a237e' }}
              disabled={loading}
            >
              {loading ? <CircularProgress size={24} /> : 'Sign In'}
            </Button>

            <Box sx={{ textAlign: 'center', mb: 2 }}>
              <Link component="button" type="button" variant="body2" onClick={() => setForgotOpen(true)}>
                Forgot password?
              </Link>
            </Box>
          </form>

          <Dialog open={forgotOpen} onClose={() => setForgotOpen(false)} maxWidth="xs" fullWidth>
            <DialogTitle>Reset Password</DialogTitle>
            <DialogContent>
              {forgotMessage ? (
                <Alert severity="success">{forgotMessage}</Alert>
              ) : (
                <TextField
                  fullWidth
                  autoFocus
                  margin="dense"
                  label="Staff e-mail address"
                  type="email"
                  value={forgotEmail}
                  onChange={(e) => setForgotEmail(e.target.value)}
                />
              )}
            </DialogContent>
            <DialogActions>
              <Button onClick={() => { setForgotOpen(false); setForgotMessage(''); }}>Close</Button>
              {!forgotMessage && (
                <Button variant="contained" onClick={handleForgot} disabled={!forgotEmail || forgotLoading}>
                  Send Reset Link
                </Button>
              )}
            </DialogActions>
          </Dialog>
        </Paper>
      </Box>
    </Container>
  );
};

export default Login;
