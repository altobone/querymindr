import { Feather } from "@expo/vector-icons";
import React, { useState } from "react";
import {
  FlatList,
  Modal,
  StyleSheet,
  Text,
  TouchableOpacity,
  View,
} from "react-native";
import { useSafeAreaInsets } from "react-native-safe-area-context";

import { useColors } from "@/hooks/useColors";
import type { Instrument } from "@/lib/api";

interface Props {
  instruments: Instrument[];
  selected: Instrument | null;
  onSelect: (instrument: Instrument) => void;
}

export function InstrumentPicker({ instruments, selected, onSelect }: Props) {
  const colors = useColors();
  const insets = useSafeAreaInsets();
  const [open, setOpen] = useState(false);

  const styles = StyleSheet.create({
    trigger: {
      flexDirection: "row",
      alignItems: "center",
      justifyContent: "space-between",
      backgroundColor: colors.inputBackground,
      borderWidth: 1,
      borderColor: colors.border,
      borderRadius: 10,
      paddingHorizontal: 14,
      paddingVertical: 14,
    },
    triggerText: {
      fontSize: 15,
      fontFamily: "Inter_400Regular",
      color: selected ? colors.foreground : colors.mutedForeground,
    },
    overlay: {
      flex: 1,
      backgroundColor: "rgba(0,0,0,0.6)",
      justifyContent: "flex-end",
    },
    sheet: {
      backgroundColor: colors.card,
      borderTopLeftRadius: 20,
      borderTopRightRadius: 20,
      paddingBottom: insets.bottom + 8,
      maxHeight: "70%",
    },
    handle: {
      width: 40,
      height: 4,
      backgroundColor: colors.border,
      borderRadius: 2,
      alignSelf: "center",
      marginTop: 12,
      marginBottom: 8,
    },
    sheetTitle: {
      fontSize: 16,
      fontFamily: "Inter_600SemiBold",
      color: colors.foreground,
      textAlign: "center",
      paddingVertical: 12,
    },
    item: {
      flexDirection: "row",
      alignItems: "center",
      justifyContent: "space-between",
      paddingHorizontal: 20,
      paddingVertical: 14,
      borderBottomWidth: 1,
      borderBottomColor: colors.border,
    },
    itemText: {
      fontSize: 15,
      fontFamily: "Inter_400Regular",
      color: colors.foreground,
    },
    itemTextSelected: {
      color: colors.primary,
      fontFamily: "Inter_600SemiBold",
    },
  });

  return (
    <>
      <TouchableOpacity
        testID="instrument-picker"
        style={styles.trigger}
        onPress={() => setOpen(true)}
        activeOpacity={0.7}
      >
        <Text style={styles.triggerText}>
          {selected ? selected.name : "Select your instrument"}
        </Text>
        <Feather name="chevron-down" size={18} color={colors.mutedForeground} />
      </TouchableOpacity>

      <Modal
        visible={open}
        transparent
        animationType="slide"
        onRequestClose={() => setOpen(false)}
      >
        <TouchableOpacity
          style={styles.overlay}
          activeOpacity={1}
          onPress={() => setOpen(false)}
        >
          <View
            style={styles.sheet}
            onStartShouldSetResponder={() => true}
          >
            <View style={styles.handle} />
            <Text style={styles.sheetTitle}>Select Instrument</Text>
            <FlatList
              data={instruments}
              keyExtractor={(item) => String(item.id)}
              renderItem={({ item }) => {
                const isSelected = selected?.id === item.id;
                return (
                  <TouchableOpacity
                    testID={`instrument-option-${item.id}`}
                    style={styles.item}
                    onPress={() => {
                      onSelect(item);
                      setOpen(false);
                    }}
                  >
                    <Text
                      style={[
                        styles.itemText,
                        isSelected && styles.itemTextSelected,
                      ]}
                    >
                      {item.name}
                    </Text>
                    {isSelected && (
                      <Feather name="check" size={18} color={colors.primary} />
                    )}
                  </TouchableOpacity>
                );
              }}
            />
          </View>
        </TouchableOpacity>
      </Modal>
    </>
  );
}
