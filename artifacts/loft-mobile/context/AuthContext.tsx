import * as SecureStore from "expo-secure-store";
import React, {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useState,
} from "react";
import { Platform } from "react-native";

import { encodeBase64 } from "@/lib/base64";

const USERNAME_KEY = "loft_username";
const PASSWORD_KEY = "loft_app_password";

interface AuthState {
  username: string | null;
  password: string | null;
  isLoading: boolean;
  isAuthenticated: boolean;
}

interface AuthContextValue extends AuthState {
  login: (username: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  getAuthHeader: () => string | null;
}

const AuthContext = createContext<AuthContextValue | null>(null);

async function secureGet(key: string): Promise<string | null> {
  if (Platform.OS === "web") {
    return localStorage.getItem(key);
  }
  return SecureStore.getItemAsync(key);
}

async function secureSet(key: string, value: string): Promise<void> {
  if (Platform.OS === "web") {
    localStorage.setItem(key, value);
    return;
  }
  return SecureStore.setItemAsync(key, value);
}

async function secureDelete(key: string): Promise<void> {
  if (Platform.OS === "web") {
    localStorage.removeItem(key);
    return;
  }
  return SecureStore.deleteItemAsync(key);
}

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [state, setState] = useState<AuthState>({
    username: null,
    password: null,
    isLoading: true,
    isAuthenticated: false,
  });

  useEffect(() => {
    (async () => {
      const username = await secureGet(USERNAME_KEY);
      const password = await secureGet(PASSWORD_KEY);
      setState({
        username,
        password,
        isLoading: false,
        isAuthenticated: !!(username && password),
      });
    })();
  }, []);

  const login = useCallback(async (username: string, password: string) => {
    await secureSet(USERNAME_KEY, username);
    await secureSet(PASSWORD_KEY, password);
    setState({
      username,
      password,
      isLoading: false,
      isAuthenticated: true,
    });
  }, []);

  const logout = useCallback(async () => {
    await secureDelete(USERNAME_KEY);
    await secureDelete(PASSWORD_KEY);
    setState({
      username: null,
      password: null,
      isLoading: false,
      isAuthenticated: false,
    });
  }, []);

  const getAuthHeader = useCallback(() => {
    if (!state.username || !state.password) return null;
    const credentials = `${state.username}:${state.password}`;
    const encoded = encodeBase64(credentials);
    return `Basic ${encoded}`;
  }, [state.username, state.password]);

  return (
    <AuthContext.Provider value={{ ...state, login, logout, getAuthHeader }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used inside AuthProvider");
  return ctx;
}
