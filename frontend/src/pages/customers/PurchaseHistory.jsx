import React from 'react';
import { Alert, Box } from '@mui/material';
import { PageHeader } from '../../components/common/UI';
import PurchaseTable from '../../components/customers/PurchaseTable';

const PurchaseHistory = () => (
  <Box>
    <PageHeader
      title="Purchase History"
      subtitle="Customer transactions from the Orders module, with verification and purchase association"
    />
    <Alert severity="info" variant="outlined" sx={{ mb: 2 }}>
      Transactions are read directly from recorded orders. Open a transaction to view its items, verify totals and loyalty postings, or link a walk-in sale to a customer account.
    </Alert>
    <PurchaseTable />
  </Box>
);

export default PurchaseHistory;
