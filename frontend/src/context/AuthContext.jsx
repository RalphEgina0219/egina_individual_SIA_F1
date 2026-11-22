import React, { createContext, useState, useContext, useEffect, useCallback } from 'react';
import { authService } from '../services/api';

const AuthContext = createContext({});


// eslint-disable-next-line react-refresh/only-export-components
export const useAuth = () => useContext(AuthContext);

export const AuthProvider = ({ children }) => {
    const [user, setUser] = useState(() => authService.getCurrentUser());
    const loading = false;

    // Refresh the stored user so role / permission changes take effect.
    useEffect(() => {
        if (!authService.isAuthenticated()) return;
        authService.me()
            .then((fresh) => {
                authService.storeUser(fresh);
                setUser(fresh);
            })
            .catch(() => {});
    }, []);

    const login = async (email, password) => {
        try {
            const data = await authService.login(email, password);
            setUser(data.user);
            return { success: true, data };
        } catch (error) {
            return {
                success: false,
                message: error.response?.data?.message || 'Login failed'
            };
        }
    };

    const logout = async () => {
        await authService.logout();
        setUser(null);
    };

    // Permissions come from the backend (App\Support\Permissions); the
    // backend enforces them, the UI only hides what the user cannot use.
    const can = useCallback((permission) => {
        const permissions = user?.permissions || [];
        const list = Array.isArray(permission) ? permission : [permission];
        return list.some((p) => permissions.includes(p));
    }, [user]);

    const value = {
        user,
        loading,
        login,
        logout,
        can,
        isAuthenticated: !!user,
        hasRole: (role) => user?.role === role,
        hasAnyRole: (roles) => roles.includes(user?.role),
    };

    return (
        <AuthContext.Provider value={value}>
            {children}
        </AuthContext.Provider>
    );
};
