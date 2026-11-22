export const emptyCustomer = {
  full_name: '',
  phone: '',
  email: '',
  birthday: '',
  address: '',
  preferred_branch_id: '',
  preferred_contact_method: 'SMS',
  customer_type: 'Individual',
  registration_channel: 'In-store',
  notes: '',
};

/**
 * Client-side checks mirroring the backend Form Request (the backend remains
 * the source of truth and its 422 errors are shown per field).
 */
export const validateCustomer = (values) => {
  const errors = {};
  if (!values.full_name?.trim() || values.full_name.trim().length < 2) errors.full_name = 'Full name is required.';
  if (!values.phone?.trim()) errors.phone = 'Contact number is required.';
  else if (!/^[0-9+\-\s()]{7,20}$/.test(values.phone.trim())) errors.phone = 'Enter a valid contact number.';
  if (values.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(values.email)) errors.email = 'Enter a valid e-mail address.';
  if (values.preferred_contact_method === 'Email' && !values.email) errors.email = 'E-mail is required when the preferred contact method is Email.';
  if (values.birthday && new Date(values.birthday) >= new Date()) errors.birthday = 'Birthday must be in the past.';
  return errors;
};
