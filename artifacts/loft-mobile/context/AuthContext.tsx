import * as SecureStore from "expo-secure-store";
import React, {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useState,
} from "react";
import { Platform } from "react-native";

const USERNAME_KEY = "loft_username";
const TOKEN_KEY = "loft_jwt_token";

interface AuthState {
  username: string | null;
  token: string | null;
  isLoading: boolean;
  isAuthenticated: boolean;
}

interface AuthContextValue extends AuthState {
  login: (username: string, token: string) => Promise<void>;
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
    token: null,
    isLoading: true,
    isAuthenticated: false,
  });

  useEffect(() => {
    (async () => {
      const username = await secureGet(USERNAME_KEY);
      const token = await secureGet(TOKEN_KEY);
      setState({
        username,
        token,
        isLoading: false,
        isAuthenticated: !!(username && token),
      });
    })();
  }, []);

  const login = useCallback(async (username: string, token: string) => {
    await secureSet(USERNAME_KEY, username);
    await secureSet(TOKEN_KEY, token);
    setState({
      username,
      token,
      isLoading: false,
      isAuthenticated: true,
    });
  }, []);

  const logout = useCallback(async () => {
    await secureDelete(USERNAME_KEY);
    await secureDelete(TOKEN_KEY);
    setState({
      username: null,
      token: null,
      isLoading: false,
      isAuthenticated: false,
    });
  }, []);

  const getAuthHeader = useCallback(() => {
    if (!state.token) return null;
    return `Bearer ${state.token}`;
  }, [state.token]);

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
