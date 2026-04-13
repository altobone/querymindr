import { Audio } from "expo-av";
import * as Haptics from "expo-haptics";
import { Feather } from "@expo/vector-icons";
import React, { useCallback, useEffect, useRef, useState } from "react";
import {
  ActivityIndicator,
  Platform,
  StyleSheet,
  Text,
  TouchableOpacity,
  View,
} from "react-native";

import { useColors } from "@/hooks/useColors";

interface RecordingControlsProps {
  onRecordingComplete: (uri: string, mimeType: string) => void;
  onClear: () => void;
  recordingUri: string | null;
}

type RecordState = "idle" | "recording" | "done";

function formatTime(seconds: number): string {
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return `${m}:${s.toString().padStart(2, "0")}`;
}

export function RecordingControls({
  onRecordingComplete,
  onClear,
  recordingUri,
}: RecordingControlsProps) {
  const colors = useColors();
  const [state, setState] = useState<RecordState>(recordingUri ? "done" : "idle");
  const [elapsed, setElapsed] = useState(0);
  const [permissionStatus, setPermissionStatus] = useState<"unknown" | "granted" | "denied">("unknown");
  const [error, setError] = useState<string | null>(null);
  const recordingRef = useRef<Audio.Recording | null>(null);
  const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const soundRef = useRef<Audio.Sound | null>(null);
  const [isPlaying, setIsPlaying] = useState(false);

  useEffect(() => {
    (async () => {
      if (Platform.OS === "web") {
        setPermissionStatus("granted");
        return;
      }
      const { status } = await Audio.getPermissionsAsync();
      setPermissionStatus(status === "granted" ? "granted" : "unknown");
    })();
    return () => {
      if (timerRef.current) clearInterval(timerRef.current);
      soundRef.current?.unloadAsync();
    };
  }, []);

  useEffect(() => {
    if (recordingUri) {
      setState("done");
    }
  }, [recordingUri]);

  const requestPermission = useCallback(async () => {
    const { status } = await Audio.requestPermissionsAsync();
    const granted = status === "granted";
    setPermissionStatus(granted ? "granted" : "denied");
    return granted;
  }, []);

  const startRecording = useCallback(async () => {
    setError(null);
    let allowed = permissionStatus === "granted";
    if (!allowed) {
      allowed = await requestPermission();
    }
    if (!allowed) {
      setPermissionStatus("denied");
      return;
    }
    try {
      await Audio.setAudioModeAsync({
        allowsRecordingIOS: true,
        playsInSilentModeIOS: true,
      });
      const recording = new Audio.Recording();
      await recording.prepareToRecordAsync(
        Audio.RecordingOptionsPresets.HIGH_QUALITY
      );
      await recording.startAsync();
      recordingRef.current = recording;
      setState("recording");
      setElapsed(0);
      timerRef.current = setInterval(() => setElapsed((t) => t + 1), 1000);
      Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Medium);
    } catch (error: unknown) {
      setError(error instanceof Error ? error.message : "Could not start recording");
    }
  }, [permissionStatus, requestPermission]);

  const stopRecording = useCallback(async () => {
    if (!recordingRef.current) return;
    if (timerRef.current) {
      clearInterval(timerRef.current);
      timerRef.current = null;
    }
    try {
      await recordingRef.current.stopAndUnloadAsync();
      const uri = recordingRef.current.getURI();
      recordingRef.current = null;
      if (uri) {
        onRecordingComplete(uri, "audio/m4a");
        setState("done");
        Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success);
      }
    } catch (error: unknown) {
      setError(error instanceof Error ? error.message : "Could not stop recording");
      setState("idle");
    }
  }, [onRecordingComplete]);

  const clearRecording = useCallback(() => {
    soundRef.current?.unloadAsync();
    soundRef.current = null;
    setIsPlaying(false);
    setState("idle");
    setElapsed(0);
    onClear();
    Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light);
  }, [onClear]);

  const togglePlayback = useCallback(async () => {
    if (!recordingUri) return;
    try {
      if (soundRef.current) {
        if (isPlaying) {
          await soundRef.current.pauseAsync();
          setIsPlaying(false);
        } else {
          await soundRef.current.playAsync();
          setIsPlaying(true);
        }
      } else {
        const { sound } = await Audio.Sound.createAsync({ uri: recordingUri });
        soundRef.current = sound;
        sound.setOnPlaybackStatusUpdate((status) => {
          if ("isLoaded" in status && status.isLoaded) {
            if (status.didJustFinish) {
              setIsPlaying(false);
            }
          }
        });
        await sound.playAsync();
        setIsPlaying(true);
      }
    } catch {
      setError("Could not play recording");
    }
  }, [recordingUri, isPlaying]);

  const styles = StyleSheet.create({
    container: { alignItems: "center", gap: 16 },
    recordBtn: {
      width: 80,
      height: 80,
      borderRadius: 40,
      backgroundColor:
        state === "recording"
          ? colors.recording
          : state === "done"
          ? colors.success
          : colors.primary,
      alignItems: "center",
      justifyContent: "center",
      shadowColor: "#000",
      shadowOffset: { width: 0, height: 4 },
      shadowOpacity: 0.3,
      shadowRadius: 8,
      elevation: 8,
    },
    timer: {
      fontSize: 32,
      fontFamily: "Inter_600SemiBold",
      color: colors.recording,
      letterSpacing: 2,
    },
    statusText: {
      fontSize: 13,
      fontFamily: "Inter_400Regular",
      color: colors.mutedForeground,
    },
    actions: { flexDirection: "row", gap: 16, alignItems: "center" },
    secondaryBtn: {
      flexDirection: "row",
      gap: 6,
      alignItems: "center",
      paddingHorizontal: 14,
      paddingVertical: 8,
      borderRadius: 8,
      backgroundColor: colors.secondary,
    },
    secondaryBtnText: {
      fontSize: 13,
      fontFamily: "Inter_500Medium",
      color: colors.foreground,
    },
    errorText: {
      fontSize: 13,
      fontFamily: "Inter_400Regular",
      color: colors.destructive,
      textAlign: "center",
    },
    deniedText: {
      fontSize: 14,
      fontFamily: "Inter_400Regular",
      color: colors.mutedForeground,
      textAlign: "center",
    },
  });

  if (permissionStatus === "denied") {
    return (
      <View style={styles.container}>
        <Feather name="mic-off" size={32} color={colors.mutedForeground} />
        <Text style={styles.deniedText}>
          Microphone access denied. Please enable it in Settings to record.
        </Text>
      </View>
    );
  }

  return (
    <View style={styles.container}>
      {state === "recording" && (
        <Text style={styles.timer}>{formatTime(elapsed)}</Text>
      )}

      <TouchableOpacity
        testID="record-button"
        style={styles.recordBtn}
        onPress={state === "recording" ? stopRecording : startRecording}
        activeOpacity={0.8}
      >
        {state === "done" ? (
          <Feather name="check" size={32} color="#fff" />
        ) : state === "recording" ? (
          <Feather name="square" size={24} color="#fff" />
        ) : (
          <Feather name="mic" size={32} color={colors.primaryForeground} />
        )}
      </TouchableOpacity>

      {state === "idle" && (
        <Text style={styles.statusText}>Tap to record</Text>
      )}

      {state === "recording" && (
        <Text style={styles.statusText}>Recording… tap to stop</Text>
      )}

      {state === "done" && (
        <View style={styles.actions}>
          <TouchableOpacity
            testID="play-button"
            style={styles.secondaryBtn}
            onPress={togglePlayback}
          >
            <Feather
              name={isPlaying ? "pause" : "play"}
              size={14}
              color={colors.foreground}
            />
            <Text style={styles.secondaryBtnText}>
              {isPlaying ? "Pause" : "Play"}
            </Text>
          </TouchableOpacity>
          <TouchableOpacity
            testID="re-record-button"
            style={styles.secondaryBtn}
            onPress={clearRecording}
          >
            <Feather name="refresh-cw" size={14} color={colors.foreground} />
            <Text style={styles.secondaryBtnText}>Re-record</Text>
          </TouchableOpacity>
        </View>
      )}

      {error && <Text style={styles.errorText}>{error}</Text>}
    </View>
  );
}
