import { Feather } from "@expo/vector-icons";
import * as DocumentPicker from "expo-document-picker";
import * as Haptics from "expo-haptics";
import { router } from "expo-router";
import { KeyboardAwareScrollView } from "react-native-keyboard-controller";
import React, { useCallback, useEffect, useState } from "react";
import {
  ActivityIndicator,
  Platform,
  StyleSheet,
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

type SubmissionMode = "record" | "link" | "upload";

function toMessage(error: unknown): string {
  if (error instanceof Error) return error.message;
  return String(error);
}

export default function SubmissionScreen() {
  const colors = useColors();
  const insets = useSafeAreaInsets();
  const { logout, getAuthHeader, isAuthenticated, isLoading } = useAuth();

  useEffect(() => {
    if (!isLoading && !isAuthenticated) {
      router.replace("/");
    }
  }, [isLoading, isAuthenticated]);

  const [mode, setMode] = useState<SubmissionMode>("record");
  const [recordingUri, setRecordingUri] = useState<string | null>(null);
  const [videoUrl, setVideoUrl] = useState("");
  const [videoStartTime, setVideoStartTime] = useState("");
  const [mp3File, setMp3File] = useState<{ uri: string; name: string; size?: number } | null>(null);
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

  const handleRecordingComplete = useCallback((uri: string) => {
    setRecordingUri(uri);
  }, []);

  const handleClearRecording = useCallback(() => {
    setRecordingUri(null);
  }, []);

  const handlePickMp3 = useCallback(async () => {
    const result = await DocumentPicker.getDocumentAsync({
      type: "audio/mpeg",
      copyToCacheDirectory: true,
    });
    if (result.canceled) return;
    const asset = result.assets[0];
    setMp3File({ uri: asset.uri, name: asset.name, size: asset.size });
  }, []);

  const handleClearMp3 = useCallback(() => {
    setMp3File(null);
  }, []);

  const formatFileSize = (bytes?: number): string => {
    if (!bytes) return "";
    if (bytes < 1024 * 1024) return ` (${Math.round(bytes / 1024)} KB)`;
    return ` (${(bytes / (1024 * 1024)).toFixed(1)} MB)`;
  };

  const validateForm = (): string | null => {
    if (mode === "record" && !recordingUri) {
      return "Please record your playing first.";
    }
    if (mode === "link" && !videoUrl.trim()) {
      return "Please enter a YouTube or Vimeo URL.";
    }
    if (mode === "upload" && !mp3File) {
      return "Please select an MP3 file to upload.";
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
        await uploadToS3(upload_url, recordingUri);
        s3ObjectKey = object_key;
      } else if (mode === "upload" && mp3File) {
        const ext = mp3File.name.split(".").pop() ?? "mp3";
        const filename =
          "upload_" + Date.now().toString() + Math.random().toString(36).substring(2, 9) + "." + ext;
        const { upload_url, object_key } = await presignUpload(filename, authHeader);
        await uploadToS3(upload_url, mp3File.uri);
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
    } catch (error: unknown) {
      const msg = toMessage(error) || "Submission failed. Please try again.";
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
      gap: 8,
    },
    modeBtn: {
      flex: 1,
      flexDirection: "row",
      alignItems: "center",
      justifyContent: "center",
      gap: 6,
      paddingVertical: 11,
      borderRadius: 10,
      backgroundColor: colors.secondary,
    },
    modeBtnActive: {
      backgroundColor: colors.primary,
    },
    modeBtnText: {
      fontSize: 12,
      fontFamily: "Inter_500Medium",
      color: colors.secondaryForeground,
    },
    modeBtnTextActive: {
      color: colors.primaryForeground,
    },
    uploadArea: {
      borderWidth: 1,
      borderColor: colors.border,
      borderStyle: "dashed",
      borderRadius: 12,
      padding: 24,
      alignItems: "center",
      gap: 10,
    },
    uploadAreaActive: {
      borderColor: colors.primary,
      backgroundColor: "rgba(255,255,255,0.03)",
    },
    uploadIcon: {
      opacity: 0.6,
    },
    uploadHint: {
      fontSize: 14,
      fontFamily: "Inter_400Regular",
      color: colors.mutedForeground,
      textAlign: "center",
    },
    uploadBtn: {
      flexDirection: "row",
      alignItems: "center",
      gap: 8,
      paddingVertical: 10,
      paddingHorizontal: 20,
      borderRadius: 8,
      backgroundColor: colors.secondary,
    },
    uploadBtnText: {
      fontSize: 14,
      fontFamily: "Inter_500Medium",
      color: colors.secondaryForeground,
    },
    fileRow: {
      flexDirection: "row",
      alignItems: "center",
      gap: 10,
      backgroundColor: colors.secondary,
      borderRadius: 10,
      padding: 12,
    },
    fileName: {
      flex: 1,
      fontSize: 13,
      fontFamily: "Inter_400Regular",
      color: colors.foreground,
    },
    clearBtn: {
      padding: 4,
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
          onPress={async () => {
            await logout();
            router.replace("/");
          }}
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
                  size={14}
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
                  size={14}
                  color={mode === "link" ? colors.primaryForeground : colors.secondaryForeground}
                />
                <Text style={[styles.modeBtnText, mode === "link" && styles.modeBtnTextActive]}>
                  Video Link
                </Text>
              </TouchableOpacity>
              <TouchableOpacity
                testID="mode-upload"
                style={[styles.modeBtn, mode === "upload" && styles.modeBtnActive]}
                onPress={() => setMode("upload")}
              >
                <Feather
                  name="upload"
                  size={14}
                  color={mode === "upload" ? colors.primaryForeground : colors.secondaryForeground}
                />
                <Text style={[styles.modeBtnText, mode === "upload" && styles.modeBtnTextActive]}>
                  MP3 File
                </Text>
              </TouchableOpacity>
            </View>

            {mode === "record" && (
              <RecordingControls
                onRecordingComplete={handleRecordingComplete}
                onClear={handleClearRecording}
                recordingUri={recordingUri}
              />
            )}

            {mode === "link" && (
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
                {videoUrl.trim().length > 0 && (
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
                )}
              </View>
            )}

            {mode === "upload" && (
              <View style={{ gap: 10 }}>
                {mp3File ? (
                  <View style={styles.fileRow}>
                    <Feather name="music" size={16} color={colors.primary} />
                    <Text style={styles.fileName} numberOfLines={1}>
                      {mp3File.name}{formatFileSize(mp3File.size)}
                    </Text>
                    <TouchableOpacity style={styles.clearBtn} onPress={handleClearMp3}>
                      <Feather name="x" size={16} color={colors.mutedForeground} />
                    </TouchableOpacity>
                  </View>
                ) : (
                  <TouchableOpacity
                    testID="pick-mp3-button"
                    style={[styles.uploadArea]}
                    onPress={handlePickMp3}
                    activeOpacity={0.7}
                  >
                    <Feather name="upload" size={28} color={colors.mutedForeground} style={styles.uploadIcon} />
                    <Text style={styles.uploadHint}>Tap to select an MP3 file{"\n"}from your device</Text>
                    <View style={styles.uploadBtn}>
                      <Feather name="folder" size={14} color={colors.secondaryForeground} />
                      <Text style={styles.uploadBtnText}>Browse Files</Text>
                    </View>
                  </TouchableOpacity>
                )}
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
