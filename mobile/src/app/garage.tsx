import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CameraView, useCameraPermissions } from 'expo-camera';
import { router } from 'expo-router';
import { useEffect, useMemo, useState } from 'react';
import { FlatList, StyleSheet, TextInput, View } from 'react-native';
import Animated, { FadeIn, FadeInRight, FadeOutLeft, LinearTransition, useAnimatedStyle, withSpring } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ApiError, get, post, put } from '@/api/client';
import type { Vehicle } from '@/api/types';
import { useGarage, useSession } from '@/state/session';
import { Button } from '@/ui/Button';
import { Icon } from '@/ui/Icon';
import { haptic, Press } from '@/ui/Press';
import { Skeleton } from '@/ui/Skeleton';
import { Text } from '@/ui/Text';
import { toast } from '@/ui/Toast';
import { spring } from '@/theme/motion';
import { useTheme } from '@/theme/theme';
import { font, palette, radius } from '@/theme/tokens';

type Item = { id: number; name: string };
type Step = 'make' | 'model' | 'year' | 'engine';
const STEPS: Step[] = ['make', 'model', 'year', 'engine'];

/**
 * The garage: pick the vehicle you're shopping for (make → model → year → engine) or scan its VIN.
 * Every list in the app then shows "Fits your vehicle", and signed-in shoppers keep it on their account.
 */
export default function GarageScreen() {
  const { c } = useTheme();
  const insets = useSafeAreaInsets();
  const qc = useQueryClient();
  const { vehicle, saved, setVehicle, forget } = useGarage();
  const signedIn = !!useSession((s) => s.token);

  const [picking, setPicking] = useState(!vehicle && !saved.length);
  const [scan, setScan] = useState(false);
  const [step, setStep] = useState<Step>('make');
  const [make, setMake] = useState<Item | null>(null);
  const [model, setModel] = useState<Item | null>(null);
  const [year, setYear] = useState<number | null>(null);
  const [filter, setFilter] = useState('');

  const choose = async (v: Vehicle) => {
    haptic('success');
    setVehicle(v);
    void qc.invalidateQueries();
    if (signedIn) void put('/garage', { vehicle_id: v.id }).catch(() => {});
    toast(`Shopping for ${v.label}`);
    router.back();
  };

  const resolve = useMutation({
    mutationFn: (engineId: number | null) =>
      get<{ vehicle: Vehicle }>('/vehicles/resolve', { model_id: model!.id, year: year!, engine_id: engineId ?? undefined }).then((r) => r.vehicle),
    onSuccess: choose,
    onError: (e) => toast(e instanceof ApiError ? e.message : 'We don\'t have that vehicle yet.', 'error'),
  });

  const list = useQuery({
    queryKey: ['vehicles', step, make?.id, model?.id, year],
    queryFn: async (): Promise<Item[]> => {
      if (step === 'make') return (await get<{ makes: Item[] }>('/vehicles/makes')).makes;
      if (step === 'model') return (await get<{ models: Item[] }>(`/vehicles/makes/${make!.id}/models`)).models;
      if (step === 'year') return (await get<{ years: number[] }>(`/vehicles/models/${model!.id}/years`)).years.map((y) => ({ id: y, name: String(y) }));
      return (await get<{ engines: Item[] }>(`/vehicles/models/${model!.id}/years/${year}/engines`)).engines;
    },
    enabled: picking,
  });

  // No engines listed for this year: the year is enough.
  const items = list.data;
  const noEngines = step === 'engine' && !!items && items.length === 0;
  useEffect(() => {
    if (noEngines && !resolve.isPending && !resolve.isSuccess) resolve.mutate(null);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [noEngines]);

  const shown = useMemo(() => {
    const f = filter.trim().toLowerCase();
    return f ? (items ?? []).filter((i) => i.name.toLowerCase().includes(f)) : items ?? [];
  }, [items, filter]);

  const pick = (item: Item) => {
    haptic('selection');
    setFilter('');
    if (step === 'make') {
      setMake(item);
      setStep('model');
    } else if (step === 'model') {
      setModel(item);
      setStep('year');
    } else if (step === 'year') {
      setYear(item.id);
      setStep('engine');
    } else {
      resolve.mutate(item.id);
    }
  };

  const back = () => {
    setFilter('');
    const i = STEPS.indexOf(step);
    if (i === 0) setPicking(false);
    else setStep(STEPS[i - 1]);
  };

  const progress = useAnimatedStyle(() => ({ width: withSpring(`${((STEPS.indexOf(step) + 1) / STEPS.length) * 100}%`, spring.gentle) }));

  return (
    <View style={{ flex: 1, backgroundColor: c.bg, paddingTop: insets.top + 8 }}>
      <View style={styles.top}>
        {picking ? (
          <Press onPress={back} style={styles.iconBtn} accessibilityLabel="Back">
            <Icon name="arrow-left" size={22} />
          </Press>
        ) : (
          <View style={styles.iconBtn} />
        )}
        <Text variant="h3">{picking ? 'Add your vehicle' : 'Your garage'}</Text>
        <Press onPress={() => router.back()} style={styles.iconBtn} accessibilityLabel="Close">
          <Icon name="x" size={22} />
        </Press>
      </View>

      {!picking ? (
        <Animated.View entering={FadeIn} style={{ flex: 1, padding: 16, gap: 12 }}>
          <Text variant="small" tone="muted">
            Parts are checked against the vehicle you pick. You'll see "Fits your vehicle" across the app.
          </Text>
          {saved.map((v) => {
            const on = v.id === vehicle?.id;
            return (
              <Animated.View key={v.id} layout={LinearTransition.springify()}>
                <Press onPress={() => void choose(v)} scaleTo={0.98} style={[styles.saved, { borderColor: on ? palette.red : c.line, backgroundColor: c.surface }]}>
                  <View style={[styles.vIcon, { backgroundColor: on ? palette.red : c.surface2 }]}>
                    <Icon name="vehicle" size={22} color={on ? '#fff' : c.fg} />
                  </View>
                  <View style={{ flex: 1 }}>
                    <Text variant="bodyStrong">{v.label}</Text>
                    <Text variant="small" tone="muted">
                      {on ? 'Shopping for this vehicle' : 'Tap to shop for this one'}
                    </Text>
                  </View>
                  <Press onPress={() => forget(v.id)} style={styles.iconBtn} accessibilityLabel={`Remove ${v.label}`}>
                    <Icon name="trash" size={18} color={c.muted} />
                  </Press>
                </Press>
              </Animated.View>
            );
          })}
          <Button title="Add a vehicle" icon="plus" onPress={() => setPicking(true)} size="lg" />
          <Button title="Scan VIN" icon="search" kind="outline" onPress={() => setScan(true)} size="lg" />
          {vehicle ? (
            <Button
              title="Shop without a vehicle"
              kind="ghost"
              onPress={() => {
                setVehicle(null);
                void qc.invalidateQueries();
                if (signedIn) void put('/garage', { vehicle_id: null }).catch(() => {});
                router.back();
              }}
            />
          ) : null}
        </Animated.View>
      ) : (
        <View style={{ flex: 1 }}>
          {/* Steps: Make › Model › Year › Engine, with a red bar that grows as you go. */}
          <View style={{ paddingHorizontal: 16 }}>
            <View style={styles.crumbs}>
              {STEPS.map((s, i) => {
                const val = s === 'make' ? make?.name : s === 'model' ? model?.name : s === 'year' ? (year ? String(year) : null) : null;
                const active = s === step;
                const done = STEPS.indexOf(step) > i;
                return (
                  <Text key={s} variant="smallStrong" style={{ color: active ? c.fg : done ? c.muted : c.line, fontSize: 12 }}>
                    {val && done ? val : s.charAt(0).toUpperCase() + s.slice(1)}
                    {i < STEPS.length - 1 ? '  ›' : ''}
                  </Text>
                );
              })}
            </View>
            <View style={[styles.track, { backgroundColor: c.surface2 }]}>
              <Animated.View style={[styles.bar, progress]} />
            </View>
            <View style={[styles.search, { backgroundColor: c.surface, borderColor: c.line }]}>
              <Icon name="search" size={18} color={c.muted} />
              <TextInput
                value={filter}
                onChangeText={setFilter}
                placeholder={`Search ${step}s`}
                placeholderTextColor={c.muted}
                style={{ flex: 1, color: c.fg, fontFamily: font.regular, fontSize: 15 }}
                autoCorrect={false}
                keyboardType={step === 'year' ? 'number-pad' : 'default'}
              />
            </View>
          </View>
          <Animated.View key={step} entering={FadeInRight.springify().damping(20)} exiting={FadeOutLeft.duration(160)} style={{ flex: 1 }}>
            {list.isLoading || resolve.isPending ? (
              <View style={{ padding: 16, gap: 10 }}>
                {Array.from({ length: 8 }).map((_, i) => (
                  <Skeleton key={i} height={48} radius={radius.md} />
                ))}
              </View>
            ) : (
              <FlatList
                data={shown}
                keyExtractor={(i) => String(i.id)}
                keyboardShouldPersistTaps="handled"
                numColumns={step === 'year' ? 3 : 1}
                key={step === 'year' ? 'grid' : 'list'}
                columnWrapperStyle={step === 'year' ? { gap: 10 } : undefined}
                contentContainerStyle={{ padding: 16, gap: 10, paddingBottom: insets.bottom + 40 }}
                renderItem={({ item, index }) => (
                  <Animated.View entering={FadeIn.delay(Math.min(index, 12) * 18)} style={step === 'year' ? { flex: 1 } : undefined}>
                    <Press onPress={() => pick(item)} scaleTo={0.97} haptic={false} style={[styles.row, { backgroundColor: c.surface, borderColor: c.line, justifyContent: step === 'year' ? 'center' : 'space-between' }]}>
                      <Text variant="bodyStrong">{item.name}</Text>
                      {step !== 'year' ? <Icon name="chevron-right" size={18} color={c.muted} /> : null}
                    </Press>
                  </Animated.View>
                )}
                ListEmptyComponent={
                  <Text variant="small" tone="muted" center style={{ marginTop: 24 }}>
                    Nothing matches "{filter}".
                  </Text>
                }
              />
            )}
          </Animated.View>
        </View>
      )}

      {scan ? <VinScanner onClose={() => setScan(false)} onVehicle={(v) => void choose(v)} onPartial={(m) => {
        setScan(false);
        setPicking(true);
        if (m.make) {
          setMake(m.make);
          setStep(m.model ? 'year' : 'model');
          if (m.model) setModel(m.model);
        }
      }} /> : null}
    </View>
  );
}

type VinMatch = { make: Item | null; model: Item | null };

/** Point the camera at the VIN barcode (door jamb or windscreen), or type the 17 characters. */
function VinScanner({ onClose, onVehicle, onPartial }: { onClose: () => void; onVehicle: (v: Vehicle) => void; onPartial: (m: VinMatch) => void }) {
  const { c } = useTheme();
  const insets = useSafeAreaInsets();
  const [perm, requestPerm] = useCameraPermissions();
  const [vin, setVin] = useState('');
  const [busy, setBusy] = useState(false);

  const decode = async (raw: string) => {
    const code = raw.toUpperCase().replace(/[^A-Z0-9]/g, '').replace(/^I/, '');
    if (busy || code.length < 17) return;
    setBusy(true);
    haptic('medium');
    try {
      const r = await post<{ decoded: { year: number; make: string; model: string }; vehicle: Vehicle | null; match: { make_id: number | null; model_id: number | null } }>(
        '/vehicles/vin',
        { vin: code.slice(-17) },
      );
      if (r.vehicle) onVehicle(r.vehicle);
      else {
        toast(`Found a ${r.decoded.year} ${r.decoded.make} ${r.decoded.model ?? ''}. Pick the rest below.`, 'info');
        onPartial({
          make: r.match.make_id ? { id: r.match.make_id, name: r.decoded.make } : null,
          model: r.match.model_id ? { id: r.match.model_id, name: r.decoded.model } : null,
        });
      }
    } catch (e) {
      toast(e instanceof ApiError ? e.message : 'We couldn\'t read that VIN.', 'error');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Animated.View entering={FadeIn} style={[StyleSheet.absoluteFill, { backgroundColor: '#000' }]}>
      {perm?.granted ? (
        <CameraView
          style={StyleSheet.absoluteFill}
          facing="back"
          barcodeScannerSettings={{ barcodeTypes: ['code39', 'code128', 'datamatrix', 'qr', 'pdf417'] }}
          onBarcodeScanned={busy ? undefined : (r) => void decode(r.data)}
        />
      ) : (
        <View style={[StyleSheet.absoluteFill, { alignItems: 'center', justifyContent: 'center', padding: 32, gap: 16 }]}>
          <Icon name="search" size={48} color="#fff" />
          <Text variant="h3" style={{ color: '#fff' }} center>
            Scan your VIN
          </Text>
          <Text variant="small" style={{ color: 'rgba(255,255,255,0.75)' }} center>
            Allow the camera to scan the barcode on the driver's door jamb or at the bottom of the windscreen.
          </Text>
          <Button title="Allow camera" onPress={() => void requestPerm()} />
        </View>
      )}
      {/* A frame with the brand's cut corners to aim at. */}
      {perm?.granted ? (
        <View pointerEvents="none" style={styles.frameWrap}>
          <View style={styles.frame} />
          <Text variant="smallStrong" style={{ color: '#fff', marginTop: 14 }}>
            {busy ? 'Reading…' : 'Line up the VIN barcode'}
          </Text>
        </View>
      ) : null}
      <View style={[styles.vinBar, { paddingBottom: insets.bottom + 16, backgroundColor: c.bg }]}>
        <TextInput
          value={vin}
          onChangeText={(t) => setVin(t.toUpperCase())}
          placeholder="Or type the 17-character VIN"
          placeholderTextColor={c.muted}
          autoCapitalize="characters"
          autoCorrect={false}
          maxLength={17}
          style={[styles.vinInput, { color: c.fg, borderColor: c.line, backgroundColor: c.surface }]}
        />
        <View style={{ flexDirection: 'row', gap: 10 }}>
          <Button title="Cancel" kind="outline" onPress={onClose} style={{ flex: 1 }} />
          <Button title="Find vehicle" onPress={() => void decode(vin)} loading={busy} disabled={vin.length !== 17} style={{ flex: 1.4 }} />
        </View>
      </View>
      <Press onPress={onClose} style={[styles.close, { top: insets.top + 10 }]} accessibilityLabel="Close scanner">
        <Icon name="x" size={22} color="#fff" />
      </Press>
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  top: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 8, height: 52 },
  iconBtn: { width: 44, height: 44, alignItems: 'center', justifyContent: 'center' },
  saved: { flexDirection: 'row', alignItems: 'center', gap: 12, borderWidth: 1.5, borderRadius: radius.lg, padding: 12 },
  vIcon: { width: 44, height: 44, borderRadius: 12, alignItems: 'center', justifyContent: 'center' },
  crumbs: { flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: 4 },
  track: { height: 4, borderRadius: 2, marginTop: 10, overflow: 'hidden' },
  bar: { height: 4, borderRadius: 2, backgroundColor: palette.red },
  search: { flexDirection: 'row', alignItems: 'center', gap: 8, borderWidth: 1, borderRadius: radius.md, paddingHorizontal: 12, height: 46, marginTop: 14 },
  row: { flexDirection: 'row', alignItems: 'center', borderWidth: 1, borderRadius: radius.md, paddingHorizontal: 16, height: 52 },
  frameWrap: { position: 'absolute', top: '22%', left: 0, right: 0, alignItems: 'center' },
  frame: { width: '84%', height: 110, borderWidth: 3, borderColor: palette.red, borderRadius: 14 },
  vinBar: { position: 'absolute', left: 0, right: 0, bottom: 0, padding: 16, gap: 12, borderTopLeftRadius: 22, borderTopRightRadius: 22 },
  vinInput: { height: 50, borderWidth: 1, borderRadius: radius.md, paddingHorizontal: 14, fontFamily: font.semibold, fontSize: 16, letterSpacing: 1.5 },
  close: { position: 'absolute', right: 16, width: 44, height: 44, borderRadius: 22, backgroundColor: 'rgba(0,0,0,0.5)', alignItems: 'center', justifyContent: 'center' },
});
