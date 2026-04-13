import { Feather } from "@expo/vector-icons";
import * as Haptics from "expo-haptics";
import { router } from "expo-router";
import React, { useEffect } from "react";
import {
  Platform,
  StyleSheet,
  Text,
  TouchableOpacity,
  View,
} from "react-native";
import { useSafeAreaInsets } from "react-native-safe-area-context";

import { useColors } from "@/hooks/useColors";

export default function SuccessScreen() {
  const colors = useColors();
  const insets = useSafeAreaInsets();

  useEffect(() => {
    Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success);
  }, []);

  const styles = StyleSheet.create({
    container: {
      flex: 1,
      backgroundColor: colors.background,
      alignItems: "center",
      justifyContent: "center",
      paddingHorizontal: 32,
      paddingTop: insets.top + (Platform.OS === "web" ? 40 : 0),
      paddingBottom: insets.bottom + (Platform.OS === "web" ? 34 : 0),
    },
    iconWrap: {
      width: 88,
      height: 88,
      borderRadius: 44,
      backgroundColor: "rgba(74, 153, 96, 0.15)",
      alignItems: "center",
      justifyContent: "center",
      marginBottom: 24,
    },
    title: {
      fontSize: 26,
      fontFamily: "Inter_700Bold",
      color: colors.foreground,
      textAlign: "center",
      marginBottom: 12,
    },
    body: {
      fontSize: 15,
      fontFamily: "Inter_400Regular",
      color: colors.mutedForeground,
      textAlign: "center",
      lineHeight: 22,
      marginBottom: 40,
    },
    btn: {
      backgroundColor: colors.primary,
      borderRadius: 12,
      paddingVertical: 16,
      paddingHorizontal: 40,
      alignItems: "center",
    },
    btnText: {
      fontSize: 16,
      fontFamily: "Inter_600SemiBold",
      color: colors.primaryForeground,
    },
  });

  return (
    <View style={styles.container}>
      <View style={styles.iconWrap}>
        <Feather name="check" size={40} color={colors.success} />
      </View>
      <Text style={styles.title}>Recording Submitted!</Text>
      <Text style={styles.body}>
        Your recording has been received. A coach will review it and provide
        feedback shortly.
      </Text>
      <TouchableOpacity
        testID="submit-another-button"
        style={styles.btn}
        onPress={() => router.replace("/submission")}
        activeOpacity={0.8}
      >
        <Text style={styles.btnText}>Submit Another</Text>
      </TouchableOpacity>
    </View>
  );
}
