import React, { createContext, useCallback, useContext, useState } from 'react';
import { Alert, Snackbar } from '@mui/material';

const NotifyContext = createContext(() => {});

// eslint-disable-next-line react-refresh/only-export-components
export const useNotify = () => useContext(NotifyContext);

/**
 * App-wide success / error toast: notify('Saved') or notify(message, 'error').
 */
export const NotifyProvider = ({ children }) => {
  const [snackbar, setSnackbar] = useState({ open: false, message: '', severity: 'success' });

  const notify = useCallback((message, severity = 'success') => {
    setSnackbar({ open: true, message, severity });
  }, []);

  const handleClose = () => setSnackbar((prev) => ({ ...prev, open: false }));

  return (
    <NotifyContext.Provider value={notify}>
      {children}
      <Snackbar
        open={snackbar.open}
        autoHideDuration={6000}
        onClose={handleClose}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }}
      >
        <Alert onClose={handleClose} severity={snackbar.severity} variant="filled" sx={{ width: '100%' }}>
          {snackbar.message}
        </Alert>
      </Snackbar>
    </NotifyContext.Provider>
  );
};
