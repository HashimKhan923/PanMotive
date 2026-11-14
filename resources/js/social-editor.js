/*
 * Free-hand social image editor (Fabric.js). Loaded only on the Social designer page.
 * Everything on the canvas can be dragged, resized, rotated, restyled, layered and locked.
 */
import { Canvas, StaticCanvas, Textbox, Rect, Circle, Polygon, Line, FabricImage, Shadow, Gradient, util } from 'fabric';
import { SIZES, TEMPLATES } from './social-templates';

const FONTS = ['Inter', 'Arial', 'Arial Black', 'Impact', 'Georgia', 'Trebuchet MS', 'Courier New', 'Verdana'];
const EXTRA = ['pmRole', 'pmName', 'selectable', 'evented', 'lockMovementX', 'lockMovementY', 'lockScalingX', 'lockScalingY', 'lockRotation', 'visible'];

const isMulti = (o) => String(o?.type || '').toLowerCase() === 'activeselection';

function starPoints(radius, inner = 0.5, spikes = 12) {
    const pts = [];
    for (let i = 0; i < spikes * 2; i++) {
        const r = i % 2 === 0 ? radius : radius * inner;
        const a = (Math.PI / spikes) * i - Math.PI / 2;
        pts.push({ x: radius + r * Math.cos(a), y: radius + r * Math.sin(a) });
    }
    return pts;
}

function gradientFor(spec, w, h) {
    const [c1, c2] = spec.gradient;
    const gx = spec.gradientX || [0, 0];
    const gy = spec.gradientY || [0, 1];
    return new Gradient({
        type: 'linear', gradientUnits: 'pixels',
        coords: { x1: gx[0] * w, y1: gy[0] * h, x2: gx[1] * w, y2: gy[1] * h },
        colorStops: [{ offset: 0, color: c1 }, { offset: 1, color: c2 }],
    });
}

async function imageObject(spec) {
    if (!spec.src) {
        return new Rect({ left: spec.x, top: spec.y, width: spec.w, height: spec.h, fill: '#2A2F36', rx: spec.radius || 0, ry: spec.radius || 0, pmRole: 'placeholder', pmName: 'Photo' });
    }
    const im = await FabricImage.fromURL(spec.src, { crossOrigin: 'anonymous' });
    const iw = im.width; const ih = im.height;
    const s = Math.max(spec.w / iw, spec.h / ih);
    const cw = spec.w / s; const ch = spec.h / s;
    im.set({
        cropX: (iw - cw) / 2, cropY: (ih - ch) / 2, width: cw, height: ch,
        left: spec.x, top: spec.y, scaleX: s, scaleY: s, opacity: spec.opacity ?? 1,
        pmRole: spec.role || 'image', pmName: spec.role === 'logo' ? 'Logo' : spec.role === 'product' ? 'Product photo' : 'Image',
    });
    if (spec.circle) im.clipPath = new Circle({ radius: Math.min(cw, ch) / 2, originX: 'center', originY: 'center' });
    else if (spec.radius) im.clipPath = new Rect({ width: cw, height: ch, rx: spec.radius / s, ry: spec.radius / s, originX: 'center', originY: 'center' });
    return im;
}

/** Turn layout specs into Fabric objects. */
export async function buildObjects(specs, w, h) {
    const out = [];
    for (const spec of specs) {
        let o = null;
        if (spec.type === 'image') o = await imageObject(spec).catch(() => null);
        else if (spec.type === 'rect') {
            o = new Rect({ left: spec.x, top: spec.y, width: spec.w, height: spec.h, rx: spec.rx || 0, ry: spec.rx || 0, angle: spec.angle || 0, opacity: spec.opacity ?? 1, pmName: 'Shape' });
            o.set('fill', spec.gradient ? gradientFor(spec, spec.w, spec.h) : spec.fill);
        } else if (spec.type === 'circle') o = new Circle({ left: spec.x, top: spec.y, radius: spec.radius, fill: spec.fill, pmName: 'Circle' });
        else if (spec.type === 'star') o = new Polygon(starPoints(spec.radius), { left: spec.x, top: spec.y, fill: spec.fill, pmName: 'Burst' });
        else if (spec.type === 'text') {
            o = new Textbox(spec.text, {
                left: spec.x, top: spec.y, width: spec.width, fontSize: spec.fontSize, fontFamily: spec.fontFamily, fontWeight: spec.fontWeight,
                fill: spec.fill, textAlign: spec.textAlign || 'left', lineHeight: spec.lineHeight || 1.15, charSpacing: spec.charSpacing || 0,
                angle: spec.angle || 0, splitByGrapheme: false, pmName: 'Text',
            });
        }
        if (!o) continue;
        if (spec.shadow) o.set('shadow', new Shadow({ color: 'rgba(0,0,0,.25)', blur: 30, offsetX: 0, offsetY: 12 }));
        out.push(o);
    }
    return out;
}

/** Render a template straight to a PNG file (used by "Create with AI for every app"). */
export async function renderTemplate(key, size, data, name = 'post.png') {
    const { w, h } = SIZES[size];
    const el = document.createElement('canvas');
    const c = new StaticCanvas(el, { width: w, height: h, backgroundColor: '#15191E' });
    await document.fonts?.load?.('800 40px Inter').catch(() => {});
    (await buildObjects(TEMPLATES[key].build(w, h, data), w, h)).forEach((o) => c.add(o));
    c.renderAll();
    const blob = await new Promise((res) => c.toCanvasElement(1).toBlob(res, 'image/png'));
    c.dispose();
    return new File([blob], name, { type: 'image/png' });
}

/** A small preview of a template for the gallery. */
export async function renderThumb(key, size, data, width = 220) {
    const { w, h } = SIZES[size]; const z = width / w;
    const el = document.createElement('canvas');
    const c = new StaticCanvas(el, { width: Math.round(w * z), height: Math.round(h * z), backgroundColor: '#15191E' });
    c.setZoom(z);
    (await buildObjects(TEMPLATES[key].build(w, h, data), w, h)).forEach((o) => c.add(o));
    c.renderAll();
    const url = c.toDataURL({ format: 'jpeg', quality: 0.8 });
    c.dispose();
    return url;
}

/** The interactive editor, mounted by the designer page. */
export function createEditor(el, wrap, opts) {
    const state = { size: opts.size || 'square', zoom: 1, history: [], future: [], restoring: false };
    const canvas = new Canvas(el, { preserveObjectStacking: true, backgroundColor: '#FFFFFF', selectionColor: 'rgba(229,27,43,.08)', selectionBorderColor: '#E51B2B' });

    const fit = () => {
        const { w, h } = SIZES[state.size];
        const avail = Math.max(260, wrap.clientWidth - 24);
        const maxH = Math.max(320, window.innerHeight - 260);
        state.zoom = Math.min(avail / w, maxH / h, 1);
        canvas.setDimensions({ width: Math.round(w * state.zoom), height: Math.round(h * state.zoom) });
        canvas.setZoom(state.zoom);
        canvas.requestRenderAll();
    };

    const snapshot = () => JSON.stringify(canvas.toObject(EXTRA));
    const record = () => {
        if (state.restoring) return;
        state.history.push(snapshot());
        if (state.history.length > 80) state.history.shift();
        state.future = [];
        opts.onChange?.();
    };
    const restore = async (json) => {
        state.restoring = true;
        await canvas.loadFromJSON(JSON.parse(json));
        canvas.requestRenderAll();
        state.restoring = false;
        opts.onChange?.();
    };
    canvas.on('object:added', record);
    canvas.on('object:modified', record);
    canvas.on('object:removed', record);
    canvas.on('selection:created', () => opts.onSelect?.());
    canvas.on('selection:updated', () => opts.onSelect?.());
    canvas.on('selection:cleared', () => opts.onSelect?.());
    window.addEventListener('resize', fit);

    const center = (o) => { const { w, h } = SIZES[state.size]; o.set({ left: (w - o.getScaledWidth()) / 2, top: (h - o.getScaledHeight()) / 2 }); };
    const add = (o) => { canvas.add(o); canvas.setActiveObject(o); canvas.requestRenderAll(); };
    const active = () => canvas.getActiveObject();

    const api = {
        canvas, FONTS, SIZES, TEMPLATES,
        get size() { return state.size; },
        get zoom() { return state.zoom; },
        canUndo: () => state.history.length > 1,
        canRedo: () => state.future.length > 0,
        fit,
        async load(json, size) { if (size) state.size = size; fit(); await restore(json); state.history = [snapshot()]; },
        async applyTemplate(key, data) {
            const { w, h } = SIZES[state.size];
            state.restoring = true;
            canvas.clear(); canvas.backgroundColor = '#15191E';
            (await buildObjects(TEMPLATES[key].build(w, h, data), w, h)).forEach((o) => canvas.add(o));
            state.restoring = false;
            canvas.requestRenderAll(); record();
        },
        async setSize(size, keep = true) {
            const from = SIZES[state.size]; const to = SIZES[size];
            state.size = size; fit();
            if (keep) {
                const s = Math.min(to.w / from.w, to.h / from.h);
                canvas.getObjects().forEach((o) => o.set({ left: o.left * (to.w / from.w), top: o.top * (to.h / from.h), scaleX: o.scaleX * s, scaleY: o.scaleY * s }).setCoords());
                canvas.requestRenderAll(); record();
            }
        },
        addText(kind = 'heading') {
            const presets = { heading: [96, 800, 'Big headline'], sub: [52, 700, 'Add a subheading'], body: [36, 500, 'Add some text'], price: [110, 800, '$49.99'] };
            const [size, weight, text] = presets[kind];
            const o = new Textbox(text, { width: SIZES[state.size].w * 0.7, fontSize: size, fontWeight: weight, fontFamily: kind === 'price' ? 'Impact' : 'Inter', fill: kind === 'price' ? '#E51B2B' : '#15191E', pmName: 'Text' });
            center(o); add(o);
        },
        addShape(kind, color = '#E51B2B') {
            const u = SIZES[state.size].w / 1080; let o;
            if (kind === 'rect') o = new Rect({ width: 400 * u, height: 240 * u, fill: color, pmName: 'Rectangle' });
            if (kind === 'rounded') o = new Rect({ width: 420 * u, height: 120 * u, rx: 60 * u, ry: 60 * u, fill: color, pmName: 'Pill' });
            if (kind === 'circle') o = new Circle({ radius: 160 * u, fill: color, pmName: 'Circle' });
            if (kind === 'burst') o = new Polygon(starPoints(180 * u), { fill: color, pmName: 'Burst' });
            if (kind === 'line') o = new Line([0, 0, 500 * u, 0], { stroke: color, strokeWidth: 10 * u, pmName: 'Line' });
            if (kind === 'band') o = new Rect({ width: SIZES[state.size].w, height: SIZES[state.size].h * 0.35, pmName: 'Shade', fill: new Gradient({ type: 'linear', gradientUnits: 'percentage', coords: { x1: 0, y1: 0, x2: 0, y2: 1 }, colorStops: [{ offset: 0, color: 'rgba(21,25,30,0)' }, { offset: 1, color: 'rgba(21,25,30,.9)' }] }) });
            if (!o) return;
            center(o);
            if (kind === 'band') o.set({ left: 0, top: SIZES[state.size].h * 0.65 });
            add(o);
        },
        addSticker(text) {
            const o = new Textbox(text, { width: 300, fontSize: 180, textAlign: 'center', fontFamily: 'Inter', pmName: 'Sticker' });
            center(o); add(o);
        },
        async addImage(src, name = 'Image') {
            const im = await FabricImage.fromURL(src, { crossOrigin: 'anonymous' });
            const { w, h } = SIZES[state.size];
            const s = Math.min((w * 0.7) / im.width, (h * 0.7) / im.height, 1);
            im.set({ scaleX: s, scaleY: s, pmName: name });
            center(im); add(im);
        },
        async setBackgroundImage(src) {
            const { w, h } = SIZES[state.size];
            const im = await imageObject({ src, x: 0, y: 0, w, h, role: 'image' });
            im.set({ pmName: 'Background photo' });
            canvas.add(im); canvas.sendObjectToBack(im); canvas.requestRenderAll();
        },
        setBackground(color) {
            const { w, h } = SIZES[state.size];
            canvas.backgroundColor = Array.isArray(color)
                ? new Gradient({ type: 'linear', gradientUnits: 'pixels', coords: { x1: 0, y1: 0, x2: w, y2: h }, colorStops: [{ offset: 0, color: color[0] }, { offset: 1, color: color[1] }] })
                : color;
            canvas.requestRenderAll(); record();
        },
        props() {
            const o = active();
            if (!o) return null;
            const isText = o instanceof Textbox;
            return {
                type: o.type, isText, multiple: isMulti(o), name: o.pmName || o.type,
                fill: typeof o.fill === 'string' ? o.fill : '#15191E', opacity: o.opacity ?? 1,
                fontFamily: o.fontFamily, fontSize: o.fontSize, fontWeight: o.fontWeight, fontStyle: o.fontStyle, underline: o.underline,
                textAlign: o.textAlign, charSpacing: o.charSpacing, lineHeight: o.lineHeight,
                stroke: o.stroke || '', strokeWidth: o.strokeWidth || 0, shadow: !!o.shadow, locked: !!o.lockMovementX,
            };
        },
        set(prop, value) {
            const o = active(); if (!o) return;
            const apply = (x) => {
                if (prop === 'shadow') x.set('shadow', value ? new Shadow({ color: 'rgba(0,0,0,.45)', blur: 24, offsetX: 4, offsetY: 8 }) : null);
                else if (prop === 'stroke') x.set({ stroke: value || null, strokeWidth: value ? (x.strokeWidth || 4) : 0, paintFirst: 'stroke' });
                else x.set(prop, value);
            };
            isMulti(o) ? o.getObjects().forEach(apply) : apply(o);
            canvas.requestRenderAll(); record();
        },
        layer(dir) {
            const o = active(); if (!o) return;
            ({ up: () => canvas.bringObjectForward(o), down: () => canvas.sendObjectBackwards(o), top: () => canvas.bringObjectToFront(o), bottom: () => canvas.sendObjectToBack(o) })[dir]();
            canvas.requestRenderAll(); record();
        },
        async duplicate() {
            const o = active(); if (!o) return;
            const copy = await o.clone(EXTRA);
            copy.set({ left: o.left + 30, top: o.top + 30 });
            add(copy);
        },
        remove() {
            const o = active(); if (!o) return;
            (isMulti(o) ? o.getObjects() : [o]).forEach((x) => canvas.remove(x));
            canvas.discardActiveObject(); canvas.requestRenderAll();
        },
        toggleLock() {
            const o = active(); if (!o) return;
            const lock = !o.lockMovementX;
            o.set({ lockMovementX: lock, lockMovementY: lock, lockScalingX: lock, lockScalingY: lock, lockRotation: lock, hasControls: !lock });
            canvas.requestRenderAll(); record();
        },
        align(where) {
            const o = active(); if (!o) return;
            const { w, h } = SIZES[state.size]; const ow = o.getScaledWidth(); const oh = o.getScaledHeight();
            const pos = { left: { left: 0 }, centerX: { left: (w - ow) / 2 }, right: { left: w - ow }, top: { top: 0 }, middle: { top: (h - oh) / 2 }, bottom: { top: h - oh } }[where];
            o.set(pos).setCoords(); canvas.requestRenderAll(); record();
        },
        layers() {
            return canvas.getObjects().map((o, i) => ({ i, name: o.pmName || o.type, visible: o.visible !== false, locked: !!o.lockMovementX, active: canvas.getActiveObjects().includes(o) })).reverse();
        },
        selectLayer(i) { const o = canvas.getObjects()[i]; if (o) { canvas.setActiveObject(o); canvas.requestRenderAll(); } },
        toggleVisible(i) { const o = canvas.getObjects()[i]; if (o) { o.set('visible', o.visible === false); canvas.requestRenderAll(); record(); } },
        async undo() { if (state.history.length < 2) return; state.future.push(state.history.pop()); await restore(state.history[state.history.length - 1]); },
        async redo() { if (!state.future.length) return; const s = state.future.pop(); state.history.push(s); await restore(s); },
        nudge(dx, dy) { const o = active(); if (!o || o.lockMovementX) return; o.set({ left: o.left + dx, top: o.top + dy }).setCoords(); canvas.requestRenderAll(); record(); },
        toJSON: () => snapshot(),
        async toFile(name = 'design.png') {
            canvas.discardActiveObject(); canvas.requestRenderAll();
            const el2 = canvas.toCanvasElement(1 / state.zoom);
            const blob = await new Promise((res) => el2.toBlob(res, 'image/png'));
            return new File([blob], name, { type: 'image/png' });
        },
        toDataURL() { canvas.discardActiveObject(); return canvas.toDataURL({ format: 'png', multiplier: 1 / state.zoom }); },
        dispose() { window.removeEventListener('resize', fit); canvas.dispose(); },
        util,
    };
    fit();
    state.history = [snapshot()];
    return api;
}
