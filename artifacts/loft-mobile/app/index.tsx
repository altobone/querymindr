import * as Haptics from "expo-haptics";
import { router } from "expo-router";
import React, { useEffect, useRef, useState } from "react";
import {
  ActivityIndicator,
  Image,
  KeyboardAvoidingView,
  Platform,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  TouchableOpacity,
  useWindowDimensions,
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

const BANNER = require("../assets/images/loft-banner.webp");

export default function LoginScreen() {
  const colors = useColors();
  const insets = useSafeAreaInsets();
  const { width: screenWidth } = useWindowDimensions();
  const bannerHeight = Math.round(screenWidth * (968 / 1290));
  const { isAuthenticated, isLoading, login } = useAuth();

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
    } catch (err: unknown) {
      const msg = toMessage(err);
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
    banner: {
      width: screenWidth,
      height: bannerHeight,
    },
    form: {
      flex: 1,
      paddingHorizontal: 28,
      paddingTop: 28,
      paddingBottom: insets.bottom + (Platform.OS === "web" ? 34 : 20),
    },
    sectionLabel: {
      fontSize: 11,
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
    errorBox: {
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
      marginTop: 20,
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
      <View style={{ paddingTop: insets.top }}>
        <Image
          source={BANNER}
          style={styles.banner}
          resizeMode="stretch"
        />
      </View>

      <ScrollView
        contentContainerStyle={styles.form}
        keyboardShouldPersistTaps="handled"
        showsVerticalScrollIndicator={false}
      >
        <Text style={styles.sectionLabel}>Username</Text>
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

        <Text style={styles.sectionLabel}>Application Password</Text>
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
          <View style={styles.errorBox}>
            <Text style={styles.errorText}>{error}</Text>
          </View>
        )}

        <TouchableOpacity
          testID="login-button"
          style={[
            styles.button,
            (submitting || !username.trim() || !password.trim()) && styles.buttonDisabled,
          ]}
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
      </ScrollView>
    </KeyboardAvoidingView>
  );
}
