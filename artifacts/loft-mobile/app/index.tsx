import * as Haptics from "expo-haptics";
import { router } from "expo-router";
import React, { useEffect, useRef, useState } from "react";
import {
  ActivityIndicator,
  Image,
  KeyboardAvoidingView,
  Platform,
  StyleSheet,
  Text,
  TextInput,
  TouchableOpacity,
  View,
} from "react-native";
import { useSafeAreaInsets } from "react-native-safe-area-context";

import { useAuth } from "@/context/AuthContext";
import { useColors } from "@/hooks/useColors";
import { validateCredentials } from "@/lib/api";
import { encodeBase64 } from "@/lib/base64";

function toMessage(error: unknown): string {
  if (error instanceof Error) return error.message;
  return String(error);
}

export default function LoginScreen() {
  const colors = useColors();
  const insets = useSafeAreaInsets();
  const { isAuthenticated, isLoading, login, getAuthHeader } = useAuth();

  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const passwordRef = useRef<TextInput>(null);

  useEffect(() => {
    if (!isLoading && isAuthenticated) {
      router.replace("/submission");
    }
  }, [isLoading, isAuthenticated]);

  const handleLogin = async () => {
    if (!username.trim() || !password.trim()) {
      setError("Please enter your username and application password.");
      return;
    }
    setSubmitting(true);
    setError(null);
    try {
      const credentials = `${username.trim()}:${password.trim()}`;
      const encoded = encodeBase64(credentials);
      const authHeader = `Basic ${encoded}`;
      await validateCredentials(authHeader);
      await login(username.trim(), password.trim());
      Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success);
      router.replace("/submission");
    } catch (error: unknown) {
      const msg = toMessage(error);
      if (msg === "invalid_credentials" || msg.includes("401") || msg.includes("403")) {
        setError("Invalid username or application password.");
      } else if (msg === "server_error" || msg.includes("500") || msg.includes("502") || msg.includes("503")) {
        setError("The server is temporarily unavailable. Please try again shortly.");
      } else if (msg.includes("Network") || msg.includes("fetch") || msg.includes("Failed to fetch")) {
        setError("Network error. Please check your internet connection.");
      } else {
        setError("Login failed. Please check your credentials and try again.");
      }
      Haptics.notificationAsync(Haptics.NotificationFeedbackType.Error);
    } finally {
      setSubmitting(false);
    }
  };

  const styles = StyleSheet.create({
    container: {
      flex: 1,
      backgroundColor: colors.background,
    },
    inner: {
      flex: 1,
      paddingHorizontal: 28,
      paddingTop: insets.top + (Platform.OS === "web" ? 40 : 20),
      paddingBottom: insets.bottom + (Platform.OS === "web" ? 34 : 20),
      justifyContent: "center",
    },
    logo: {
      width: 72,
      height: 72,
      borderRadius: 18,
      marginBottom: 28,
      alignSelf: "center",
    },
    tagline: {
      fontSize: 13,
      fontFamily: "Inter_400Regular",
      color: colors.mutedForeground,
      textAlign: "center",
      marginTop: 6,
      marginBottom: 40,
      letterSpacing: 1.5,
      textTransform: "uppercase",
    },
    title: {
      fontSize: 28,
      fontFamily: "Inter_700Bold",
      color: colors.foreground,
      textAlign: "center",
      marginBottom: 4,
    },
    label: {
      fontSize: 12,
      fontFamily: "Inter_600SemiBold",
      color: colors.mutedForeground,
      marginBottom: 6,
      textTransform: "uppercase",
      letterSpacing: 1,
    },
    input: {
      backgroundColor: colors.inputBackground,
      borderWidth: 1,
      borderColor: colors.border,
      borderRadius: 10,
      paddingHorizontal: 14,
      paddingVertical: 14,
      fontSize: 15,
      fontFamily: "Inter_400Regular",
      color: colors.foreground,
      marginBottom: 16,
    },
    inputFocused: {
      borderColor: colors.primary,
    },
    button: {
      backgroundColor: colors.primary,
      borderRadius: 12,
      paddingVertical: 16,
      alignItems: "center",
      marginTop: 8,
    },
    buttonDisabled: {
      opacity: 0.5,
    },
    buttonText: {
      fontSize: 16,
      fontFamily: "Inter_600SemiBold",
      color: colors.primaryForeground,
    },
    error: {
      backgroundColor: "rgba(201,68,68,0.12)",
      borderRadius: 8,
      paddingHorizontal: 14,
      paddingVertical: 10,
      marginBottom: 16,
    },
    errorText: {
      fontSize: 14,
      fontFamily: "Inter_400Regular",
      color: colors.destructive,
      textAlign: "center",
    },
    hint: {
      fontSize: 12,
      fontFamily: "Inter_400Regular",
      color: colors.mutedForeground,
      textAlign: "center",
      marginTop: 16,
      lineHeight: 18,
    },
    hintLink: {
      color: colors.primary,
      fontFamily: "Inter_500Medium",
    },
  });

  if (isLoading) {
    return (
      <View style={[styles.container, { alignItems: "center", justifyContent: "center" }]}>
        <ActivityIndicator color={colors.primary} size="large" />
      </View>
    );
  }

  return (
    <KeyboardAvoidingView
      style={styles.container}
      behavior={Platform.OS === "ios" ? "padding" : "height"}
    >
      <View style={styles.inner}>
        <Image
          source={require("../assets/images/icon.png")}
          style={styles.logo}
          resizeMode="cover"
        />
        <Text style={styles.title}>The Loft</Text>
        <Text style={styles.tagline}>Music Savvy</Text>

        <Text style={styles.label}>Username</Text>
        <TextInput
          testID="username-input"
          style={styles.input}
          placeholder="WordPress username"
          placeholderTextColor={colors.mutedForeground}
          value={username}
          onChangeText={setUsername}
          autoCapitalize="none"
          autoCorrect={false}
          returnKeyType="next"
          onSubmitEditing={() => passwordRef.current?.focus()}
          editable={!submitting}
        />

        <Text style={styles.label}>Application Password</Text>
        <TextInput
          ref={passwordRef}
          testID="password-input"
          style={styles.input}
          placeholder="xxxx xxxx xxxx xxxx xxxx xxxx"
          placeholderTextColor={colors.mutedForeground}
          value={password}
          onChangeText={setPassword}
          secureTextEntry={true}
          autoCapitalize="none"
          autoCorrect={false}
          returnKeyType="go"
          onSubmitEditing={handleLogin}
          editable={!submitting}
        />

        {error && (
          <View style={styles.error}>
            <Text style={styles.errorText}>{error}</Text>
          </View>
        )}

        <TouchableOpacity
          testID="login-button"
          style={[styles.button, (submitting || !username || !password) && styles.buttonDisabled]}
          onPress={handleLogin}
          disabled={submitting || !username.trim() || !password.trim()}
          activeOpacity={0.8}
        >
          {submitting ? (
            <ActivityIndicator color={colors.primaryForeground} size="small" />
          ) : (
            <Text style={styles.buttonText}>Sign In</Text>
          )}
        </TouchableOpacity>

        <Text style={styles.hint}>
          Use your WordPress username and an{" "}
          <Text style={styles.hintLink}>Application Password</Text>
          {"\n"}from musicsavvy.com → Profile → Application Passwords
        </Text>
      </View>
    </KeyboardAvoidingView>
  );
}
