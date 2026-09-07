// Diagram backgrounds for the annotation canvas (PRESCRIPTION.md §4.11). They are painted with canvas primitives
// rather than loaded as assets so the writer never waits on a network round trip and works offline; the print path
// re-renders the strokes over its own SVG background from `drawing_json.canvas.template`.
export type DrawingTemplate = 'blank' | 'dental_adult' | 'dental_child' | 'eye_pair' | 'skeleton_front' | 'body_front_back' | 'spine' | 'abdomen';

export const DRAWING_TEMPLATES: DrawingTemplate[] = ['blank', 'dental_adult', 'dental_child', 'eye_pair', 'skeleton_front', 'body_front_back', 'spine', 'abdomen'];

const INK = '#9aa5b1';
const FAINT = '#dde3ea';

function frame(ctx: CanvasRenderingContext2D, w: number, h: number): void {
  ctx.save();
  ctx.strokeStyle = INK;
  ctx.fillStyle = INK;
  ctx.lineWidth = 1.25;
  ctx.font = '11px system-ui, sans-serif';
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  void w;
  void h;
}

function tooth(ctx: CanvasRenderingContext2D, x: number, y: number, r: number, label: string): void {
  ctx.beginPath();
  ctx.arc(x, y, r, 0, Math.PI * 2);
  ctx.stroke();
  ctx.save();
  ctx.fillStyle = INK;
  ctx.font = `${Math.round(r)}px system-ui, sans-serif`;
  ctx.fillText(label, x, y);
  ctx.restore();
}

/** Two dental arches; `perQuadrant` is 8 for adults (FDI 1–8) and 5 for children (A–E). */
function arches(ctx: CanvasRenderingContext2D, w: number, h: number, perQuadrant: number, labels: string[]): void {
  const cx = w / 2;
  const r = Math.min(w, h) * 0.34;
  const toothR = Math.max(9, r / (perQuadrant * 1.5));
  for (const [index, upper] of [true, false].entries()) {
    const cy = upper ? h * 0.3 : h * 0.72;
    for (let side = 0; side < 2; side++) {
      for (let i = 0; i < perQuadrant; i++) {
        const spread = Math.PI * 0.78;
        const step = spread / (perQuadrant * 2 - 1);
        const angle = (upper ? Math.PI : 0) + (side === 0 ? -1 : 1) * (step * (perQuadrant - 1 - i) + step * 0.5) * (upper ? -1 : 1);
        const x = cx + Math.cos(angle) * r * (side === 0 ? -1 : 1) * (side === 0 ? -1 : 1);
        void x;
        const px = cx + (side === 0 ? -1 : 1) * Math.sin(step * (i + 0.5) * 2) * r;
        const py = cy + (upper ? -1 : 1) * (Math.cos(step * (i + 0.5) * 2) * r * 0.5 - r * 0.5) * (upper ? 1 : 1);
        tooth(ctx, px, py, toothR, labels[i] ?? String(i + 1));
      }
    }
    void index;
  }
  ctx.save();
  ctx.strokeStyle = FAINT;
  ctx.beginPath();
  ctx.moveTo(cx, h * 0.1);
  ctx.lineTo(cx, h * 0.9);
  ctx.moveTo(w * 0.08, h * 0.51);
  ctx.lineTo(w * 0.92, h * 0.51);
  ctx.stroke();
  ctx.restore();
}

function eyes(ctx: CanvasRenderingContext2D, w: number, h: number): void {
  const r = Math.min(w / 5, h / 3);
  for (const [i, label] of ['RIGHT (OD)', 'LEFT (OS)'].entries()) {
    const cx = w * (i === 0 ? 0.28 : 0.72);
    const cy = h * 0.45;
    ctx.beginPath();
    ctx.arc(cx, cy, r, 0, Math.PI * 2);
    ctx.stroke();
    ctx.beginPath();
    ctx.arc(cx, cy, r * 0.55, 0, Math.PI * 2);
    ctx.stroke();
    ctx.beginPath();
    ctx.arc(cx, cy, r * 0.22, 0, Math.PI * 2);
    ctx.stroke();
    ctx.fillText(label, cx, cy + r + 16);
  }
}

function bodyOutline(ctx: CanvasRenderingContext2D, cx: number, top: number, height: number, label: string): void {
  const unit = height / 8;
  ctx.beginPath();
  ctx.arc(cx, top + unit * 0.7, unit * 0.62, 0, Math.PI * 2); // head
  ctx.stroke();
  ctx.beginPath();
  ctx.moveTo(cx - unit * 0.95, top + unit * 1.5);
  ctx.lineTo(cx + unit * 0.95, top + unit * 1.5); // shoulders
  ctx.lineTo(cx + unit * 0.75, top + unit * 4.4);
  ctx.lineTo(cx - unit * 0.75, top + unit * 4.4);
  ctx.closePath();
  ctx.stroke();
  ctx.beginPath();
  ctx.moveTo(cx - unit * 0.95, top + unit * 1.6);
  ctx.lineTo(cx - unit * 1.5, top + unit * 4.2); // arms
  ctx.moveTo(cx + unit * 0.95, top + unit * 1.6);
  ctx.lineTo(cx + unit * 1.5, top + unit * 4.2);
  ctx.moveTo(cx - unit * 0.45, top + unit * 4.4);
  ctx.lineTo(cx - unit * 0.55, top + unit * 7.6); // legs
  ctx.moveTo(cx + unit * 0.45, top + unit * 4.4);
  ctx.lineTo(cx + unit * 0.55, top + unit * 7.6);
  ctx.stroke();
  ctx.fillText(label, cx, top + height + 12);
}

function spine(ctx: CanvasRenderingContext2D, w: number, h: number): void {
  const cx = w / 2;
  const top = h * 0.08;
  const step = (h * 0.8) / 24;
  const groups: Array<[string, number]> = [['C', 7], ['T', 12], ['L', 5]];
  let index = 0;
  for (const [prefix, count] of groups) {
    for (let i = 1; i <= count; i++) {
      const y = top + step * index;
      const width = 26 + index * 0.9;
      ctx.strokeRect(cx - width / 2, y, width, step * 0.72);
      ctx.fillText(`${prefix}${i}`, cx - width / 2 - 18, y + step * 0.36);
      index++;
    }
  }
}

function abdomen(ctx: CanvasRenderingContext2D, w: number, h: number): void {
  const left = w * 0.2;
  const top = h * 0.12;
  const cellW = (w * 0.6) / 3;
  const cellH = (h * 0.7) / 3;
  const names = ['RHC', 'Epigastric', 'LHC', 'R lumbar', 'Umbilical', 'L lumbar', 'RIF', 'Hypogastric', 'LIF'];
  for (let r = 0; r < 3; r++) {
    for (let c = 0; c < 3; c++) {
      ctx.strokeRect(left + c * cellW, top + r * cellH, cellW, cellH);
      ctx.save();
      ctx.fillStyle = INK;
      ctx.font = '10px system-ui, sans-serif';
      ctx.fillText(names[r * 3 + c] ?? '', left + c * cellW + cellW / 2, top + r * cellH + 12);
      ctx.restore();
    }
  }
}

export function paintBackground(ctx: CanvasRenderingContext2D, template: DrawingTemplate, w: number, h: number): void {
  ctx.clearRect(0, 0, w, h);
  ctx.save();
  ctx.fillStyle = '#ffffff';
  ctx.fillRect(0, 0, w, h);
  ctx.restore();
  if (template === 'blank') return;

  frame(ctx, w, h);
  switch (template) {
    case 'dental_adult':
      arches(ctx, w, h, 8, ['1', '2', '3', '4', '5', '6', '7', '8']);
      break;
    case 'dental_child':
      arches(ctx, w, h, 5, ['A', 'B', 'C', 'D', 'E']);
      break;
    case 'eye_pair':
      eyes(ctx, w, h);
      break;
    case 'skeleton_front':
      bodyOutline(ctx, w / 2, h * 0.06, h * 0.82, 'Anterior');
      break;
    case 'body_front_back':
      bodyOutline(ctx, w * 0.3, h * 0.06, h * 0.78, 'Front');
      bodyOutline(ctx, w * 0.7, h * 0.06, h * 0.78, 'Back');
      break;
    case 'spine':
      spine(ctx, w, h);
      break;
    case 'abdomen':
      abdomen(ctx, w, h);
      break;
  }
  ctx.restore();
}
