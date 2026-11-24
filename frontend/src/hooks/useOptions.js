import { useEffect, useState } from 'react';
import { customerManagementService } from '../services/api';

let cache = null;
let pending = null;

const EMPTY = {
  customer_statuses: [],
  customer_types: [],
  contact_methods: [],
  registration_channels: [],
  verification_methods: [],
  feedback_types: [],
  complaint_types: [],
  feedback_categories: [],
  feedback_channels: [],
  feedback_statuses: [],
  feedback_priorities: [],
  response_types: [],
  departments: [],
  notification_types: {},
  roles: [],
  branches: [],
  membership_levels: [],
  staff: [],
  products: [],
  employees: [],
  issue_types: [],
};

/**
 * Reference lists for the Customer Management Module (statuses, branches,
 * membership levels...), loaded once from the backend configuration.
 */
export const useOptions = () => {
  const [options, setOptions] = useState(cache || EMPTY);

  useEffect(() => {
    if (cache) return;
    pending = pending || customerManagementService.options().then((res) => {
      cache = { ...EMPTY, ...res.data };
      return cache;
    }).catch((error) => {
      pending = null;
      throw error;
    });
    let active = true;
    pending.then((data) => active && setOptions(data)).catch(() => {});
    return () => { active = false; };
  }, []);

  return options;
};

export const refreshOptions = () => {
  cache = null;
  pending = null;
};
