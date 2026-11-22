import React from 'react';
import { Navigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

/**
 * `roles` keeps the original role-based check; `permission` (string or array,
 * any of) uses the permission list returned by the backend.
 */
const ProtectedRoute = ({ children, roles = [], permission }) => {
  const { user, loading, can } = useAuth();

  if (loading) {
    return <div>Loading...</div>;
  }

  if (!user) {
    return <Navigate to="/login" replace />;
  }

  if (roles.length > 0 && !roles.includes(user.role)) {
    return <Navigate to="/" replace />;
  }

  if (permission && !can(permission)) {
    return <Navigate to="/" replace />;
  }

  return children;
};

export default ProtectedRoute;
