import { Feather } from "@expo/vector-icons";
import * as Haptics from "expo-haptics";
import { router } from "expo-router";
import { KeyboardAwareScrollView } from "react-native-keyboard-controller";
import React, { useCallback, useState } from "react";
import {
  ActivityIndicator,
  Platform,
  StyleSheet,
  Switch,
  Text,
  TextInput,
  TouchableOpacity,
  View,
} from "react-native";
import { useSafeAreaInsets } from "react-native-safe-area-context";
import { useQuery } from "@tanstack/react-query";

import { InstrumentPicker } from "@/components/InstrumentPicker";
import { RecordingControls } from "@/components/RecordingControls";
import { useAuth } from "@/context/AuthContext";
import { useColors } from "@/hooks/useColors";
import {
  type Instrument,
  fetchInstruments,
  presignUpload,
  submitForm,
  uploadToS3,
} from "@/lib/api";

type SubmissionMode = "record" | "link";

export default function SubmissionScreen() {
  const colors = useColors();
  const insets = useSafeAreaInsets();
  const { logout, getAuthHeader } = useAuth();

  const [mode, setMode] = useState<SubmissionMode>("record");
  const [recordingUri, setRecordingUri] = useState<string | null>(null);
  const [recordingMime, setRecordingMime] = useState<string>("audio/m4a");
  const [videoUrl, setVideoUrl] = useState("");
  const [videoStartTime, setVideoStartTime] = useState("");
  const [doesntFeel, setDoesntFeel] = useState("");
  const [wouldImprove, setWouldImprove] = useState("");
  const [selectedInstrument, setSelectedInstrument] = useState<Instrument | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);

  const authHeader = getAuthHeader();

  const { data: instruments, isLoading: instrumentsLoading, error: instrumentsError, refetch } = useQuery({
    queryKey: ["instruments"],
    queryFn: () => fetchInstruments(authHeader!),
    enabled: !!authHeader,
    staleTime: 5 * 60 * 1000,
  });

  const handleRecordingComplete = useCallback((uri: string, mimeType: string) => {
    setRecordingUri(uri);
    setRecordingMime(mimeType);
  }, []);

  const handleClearRecording = useCallback(() => {
    setRecordingUri(null);
  }, []);

  const validateForm = (): string | null => {
    if (mode === "record" && !recordingUri) {
      return "Please record your playing first.";
    }
    if (mode === "link" && !videoUrl.trim()) {
      return "Please enter a YouTube or Vimeo URL.";
    }
    if (!doesntFeel.trim()) {
      return "Please describe what doesn't feel right.";
    }
    if (!wouldImprove.trim()) {
      return "Please describe what you'd like to improve.";
    }
    if (!selectedInstrument) {
      return "Please select your instrument.";
    }
    return null;
  };

  const handleSubmit = async () => {
    const validationError = validateForm();
    if (validationError) {
      setSubmitError(validationError);
      Haptics.notificationAsync(Haptics.NotificationFeedbackType.Error);
      return;
    }
    if (!authHeader) {
      setSubmitError("Authentication error. Please sign in again.");
      return;
    }
    setSubmitting(true);
    setSubmitError(null);
    try {
      let s3ObjectKey: string | undefined;

      if (mode === "record" && recordingUri) {
        const filename =
          "recording_" + Date.now().toString() + Math.random().toString(36).substring(2, 9) + ".m4a";
        const { upload_url, object_key } = await presignUpload(filename, authHeader);
        await uploadToS3(upload_url, recordingUri, recordingMime);
        s3ObjectKey = object_key;
      }

      await submitForm(
        {
          ...(s3ObjectKey ? { s3_object_key: s3ObjectKey } : {}),
          ...(mode === "link" && videoUrl.trim()
            ? {
                submission_video_url: videoUrl.trim(),
                ...(videoStartTime.trim()
                  ? { submission_video_sample_start: videoStartTime.trim() }
                  : {}),
              }
            : {}),
          what_doesnt_feel_right: doesntFeel.trim(),
          what_would_you_like_to_improve: wouldImprove.trim(),
          instrument: selectedInstrument!.id,
        },
        authHeader
      );

      Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success);
      router.push("/success");
    } catch (e: any) {
      const msg = e?.message || "Submission failed. Please try again.";
      setSubmitError(msg.length > 120 ? msg.slice(0, 120) + "…" : msg);
      Haptics.notificationAsync(Haptics.NotificationFeedbackType.Error);
    } finally {
      setSubmitting(false);
    }
  };

  const styles = StyleSheet.create({
    container: { flex: 1, backgroundColor: colors.background },
    header: {
      flexDirection: "row",
      alignItems: "center",
      justifyContent: "space-between",
      paddingHorizontal: 20,
      paddingTop: insets.top + (Platform.OS === "web" ? 40 : 12),
      paddingBottom: 12,
    },
    headerTitle: {
      fontSize: 18,
      fontFamily: "Inter_700Bold",
      color: colors.foreground,
    },
    headerRight: {
      padding: 4,
    },
    scrollContent: {
      paddingHorizontal: 20,
      paddingBottom: insets.bottom + (Platform.OS === "web" ? 34 : 32),
      gap: 24,
    },
    section: {
      gap: 10,
    },
    sectionTitle: {
      fontSize: 12,
      fontFamily: "Inter_600SemiBold",
      color: colors.mutedForeground,
      textTransform: "uppercase",
      letterSpacing: 1.2,
    },
    card: {
      backgroundColor: colors.card,
      borderRadius: 16,
      padding: 24,
      gap: 16,
      borderWidth: 1,
      borderColor: colors.border,
    },
    modeToggle: {
      flexDirection: "row",
      gap: 10,
    },
    modeBtn: {
      flex: 1,
      flexDirection: "row",
      alignItems: "center",
      justifyContent: "center",
      gap: 8,
      paddingVertical: 12,
      borderRadius: 10,
      backgroundColor: colors.secondary,
    },
    modeBtnActive: {
      backgroundColor: colors.primary,
    },
    modeBtnText: {
      fontSize: 14,
      fontFamily: "Inter_500Medium",
      color: colors.secondaryForeground,
    },
    modeBtnTextActive: {
      color: colors.primaryForeground,
    },
    textArea: {
      backgroundColor: colors.inputBackground,
      borderWidth: 1,
      borderColor: colors.border,
      borderRadius: 10,
      paddingHorizontal: 14,
      paddingVertical: 12,
      fontSize: 15,
      fontFamily: "Inter_400Regular",
      color: colors.foreground,
      minHeight: 100,
      textAlignVertical: "top",
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
    },
    submitBtn: {
      backgroundColor: colors.primary,
      borderRadius: 14,
      paddingVertical: 18,
      alignItems: "center",
      flexDirection: "row",
      justifyContent: "center",
      gap: 10,
    },
    submitBtnDisabled: {
      opacity: 0.5,
    },
    submitBtnText: {
      fontSize: 16,
      fontFamily: "Inter_600SemiBold",
      color: colors.primaryForeground,
    },
    error: {
      backgroundColor: "rgba(201,68,68,0.12)",
      borderRadius: 10,
      paddingHorizontal: 16,
      paddingVertical: 12,
    },
    errorText: {
      fontSize: 14,
      fontFamily: "Inter_400Regular",
      color: colors.destructive,
    },
    loadingRow: {
      flexDirection: "row",
      alignItems: "center",
      gap: 10,
      padding: 16,
    },
    loadingText: {
      fontSize: 14,
      color: colors.mutedForeground,
      fontFamily: "Inter_400Regular",
    },
    retryBtn: {
      flexDirection: "row",
      alignItems: "center",
      gap: 6,
      padding: 12,
    },
    retryText: {
      fontSize: 14,
      color: colors.primary,
      fontFamily: "Inter_500Medium",
    },
  });

  return (
    <View style={styles.container}>
      <View style={styles.header}>
        <Text style={styles.headerTitle}>Submit Recording</Text>
        <TouchableOpacity
          testID="logout-button"
          style={styles.headerRight}
          onPress={logout}
          hitSlop={{ top: 8, bottom: 8, left: 8, right: 8 }}
        >
          <Feather name="log-out" size={20} color={colors.mutedForeground} />
        </TouchableOpacity>
      </View>

      <KeyboardAwareScrollView
        style={styles.container}
        contentContainerStyle={styles.scrollContent}
        keyboardShouldPersistTaps="handled"
        bottomOffset={20}
      >
        <View style={styles.section}>
          <Text style={styles.sectionTitle}>Recording</Text>
          <View style={styles.card}>
            <View style={styles.modeToggle}>
              <TouchableOpacity
                testID="mode-record"
                style={[styles.modeBtn, mode === "record" && styles.modeBtnActive]}
                onPress={() => setMode("record")}
              >
                <Feather
                  name="mic"
                  size={16}
                  color={mode === "record" ? colors.primaryForeground : colors.secondaryForeground}
                />
                <Text style={[styles.modeBtnText, mode === "record" && styles.modeBtnTextActive]}>
                  Record
                </Text>
              </TouchableOpacity>
              <TouchableOpacity
                testID="mode-link"
                style={[styles.modeBtn, mode === "link" && styles.modeBtnActive]}
                onPress={() => setMode("link")}
              >
                <Feather
                  name="link"
                  size={16}
                  color={mode === "link" ? colors.primaryForeground : colors.secondaryForeground}
                />
                <Text style={[styles.modeBtnText, mode === "link" && styles.modeBtnTextActive]}>
                  Video Link
                </Text>
              </TouchableOpacity>
            </View>

            {mode === "record" ? (
              <RecordingControls
                onRecordingComplete={handleRecordingComplete}
                onClear={handleClearRecording}
                recordingUri={recordingUri}
              />
            ) : (
              <View style={{ gap: 10 }}>
                <TextInput
                  testID="video-url-input"
                  style={styles.input}
                  placeholder="YouTube or Vimeo URL"
                  placeholderTextColor={colors.mutedForeground}
                  value={videoUrl}
                  onChangeText={setVideoUrl}
                  autoCapitalize="none"
                  autoCorrect={false}
                  keyboardType="url"
                />
                <TextInput
                  testID="video-start-input"
                  style={styles.input}
                  placeholder="Start time (optional, e.g. 0:32)"
                  placeholderTextColor={colors.mutedForeground}
                  value={videoStartTime}
                  onChangeText={setVideoStartTime}
                  autoCapitalize="none"
                  autoCorrect={false}
                />
              </View>
            )}
          </View>
        </View>

        <View style={styles.section}>
          <Text style={styles.sectionTitle}>Feedback</Text>
          <View style={styles.card}>
            <View style={{ gap: 8 }}>
              <Text style={{ fontSize: 14, fontFamily: "Inter_500Medium", color: colors.foreground }}>
                What doesn't feel right to you?
              </Text>
              <TextInput
                testID="doesnt-feel-input"
                style={styles.textArea}
                placeholder="Describe what feels off or uncomfortable..."
                placeholderTextColor={colors.mutedForeground}
                value={doesntFeel}
                onChangeText={setDoesntFeel}
                multiline
                numberOfLines={4}
              />
            </View>

            <View style={{ gap: 8 }}>
              <Text style={{ fontSize: 14, fontFamily: "Inter_500Medium", color: colors.foreground }}>
                What would you like to improve?
              </Text>
              <TextInput
                testID="improve-input"
                style={styles.textArea}
                placeholder="Describe your goal or what you want to work on..."
                placeholderTextColor={colors.mutedForeground}
                value={wouldImprove}
                onChangeText={setWouldImprove}
                multiline
                numberOfLines={4}
              />
            </View>
          </View>
        </View>

        <View style={styles.section}>
          <Text style={styles.sectionTitle}>Instrument</Text>
          {instrumentsLoading ? (
            <View style={styles.loadingRow}>
              <ActivityIndicator size="small" color={colors.primary} />
              <Text style={styles.loadingText}>Loading instruments…</Text>
            </View>
          ) : instrumentsError ? (
            <TouchableOpacity style={styles.retryBtn} onPress={() => refetch()}>
              <Feather name="refresh-cw" size={14} color={colors.primary} />
              <Text style={styles.retryText}>Retry loading instruments</Text>
            </TouchableOpacity>
          ) : (
            <InstrumentPicker
              instruments={instruments ?? []}
              selected={selectedInstrument}
              onSelect={setSelectedInstrument}
            />
          )}
        </View>

        {submitError && (
          <View style={styles.error}>
            <Text style={styles.errorText}>{submitError}</Text>
          </View>
        )}

        <TouchableOpacity
          testID="submit-button"
          style={[styles.submitBtn, submitting && styles.submitBtnDisabled]}
          onPress={handleSubmit}
          disabled={submitting}
          activeOpacity={0.8}
        >
          {submitting ? (
            <>
              <ActivityIndicator size="small" color={colors.primaryForeground} />
              <Text style={styles.submitBtnText}>Submitting…</Text>
            </>
          ) : (
            <>
              <Feather name="send" size={18} color={colors.primaryForeground} />
              <Text style={styles.submitBtnText}>Submit Recording</Text>
            </>
          )}
        </TouchableOpacity>
      </KeyboardAwareScrollView>
    </View>
  );
}
