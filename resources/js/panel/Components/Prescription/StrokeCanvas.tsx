// The shared stylus surface behind Draw/annotate (§4.11) and Handwriting mode (§4.12). Pointer events, pressure
// aware, pen prioritised over touch once a pen has been seen in the session (so the palm never draws). Strokes are
// stored in canvas coordinates and exported both as DrawingJson and as a PNG at devicePixelRatio.
import { forwardRef, useCallback, useEffect, useImperativeHandle, useRef, useState } from 'react';
import Box from '@mui/material/Box';
import type { DrawingJson } from '@shared/types/models';
import { paintBackground, type DrawingTemplate } from '@panel/lib/prescription/drawingBackgrounds';

export type Tool = 'pen' | 'marker' | 'eraser';
export type Stroke = DrawingJson['strokes'][number];

export interface StrokeCanvasHandle {
  toPng(scale?: number): Promise<Blob | null>;
  strokes(): Stroke[];
  undo(): void;
  redo(): void;
  clear(): void;
  isEmpty(): boolean;
}

export interface StrokeCanvasProps {
  width: number;
  height: number;
  template?: DrawingTemplate;
  tool: Tool;
  color: string;
  initialStrokes?: Stroke[];
  onChange?: (strokes: Stroke[]) => void;
  /** Fires after a 2 s pause in drawing — the autosave trigger of §4.12. */
  onPause?: () => void;
  ariaLabel: string;
}

const WIDTHS: Record<Tool, number> = { pen: 2, marker: 6, eraser: 18 };
const PAUSE_MS = 2000;

export const StrokeCanvas = forwardRef<StrokeCanvasHandle, StrokeCanvasProps>(function StrokeCanvas(
  { width, height, template = 'blank', tool, color, initialStrokes = [], onChange, onPause, ariaLabel },
  ref,
) {
  const canvasRef = useRef<HTMLCanvasElement | null>(null);
  const strokes = useRef<Stroke[]>([...initialStrokes]);
  const undone = useRef<Stroke[]>([]);
  const drawing = useRef<Stroke | null>(null);
  const penSeen = useRef(false);
  const pauseTimer = useRef<number | null>(null);
  const [, force] = useState(0);

  const repaint = useCallback(() => {
    const canvas = canvasRef.current;
    const ctx = canvas?.getContext('2d');
    if (canvas == null || ctx == null) return;
    const dpr = window.devicePixelRatio || 1;
    if (canvas.width !== Math.floor(width * dpr) || canvas.height !== Math.floor(height * dpr)) {
      canvas.width = Math.floor(width * dpr);
      canvas.height = Math.floor(height * dpr);
    }
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    paintBackground(ctx, template, width, height);
    for (const stroke of strokes.current) drawStroke(ctx, stroke);
    if (drawing.current !== null) drawStroke(ctx, drawing.current);
  }, [height, template, width]);

  useEffect(() => {
    repaint();
  }, [repaint]);

  useImperativeHandle(ref, () => ({
    strokes: () => strokes.current,
    undo() {
      const last = strokes.current.pop();
      if (last !== undefined) undone.current.push(last);
      repaint();
      onChange?.(strokes.current);
      force((n) => n + 1);
    },
    redo() {
      const next = undone.current.pop();
      if (next !== undefined) strokes.current.push(next);
      repaint();
      onChange?.(strokes.current);
      force((n) => n + 1);
    },
    clear() {
      strokes.current = [];
      undone.current = [];
      repaint();
      onChange?.(strokes.current);
      force((n) => n + 1);
    },
    isEmpty: () => strokes.current.length === 0,
    async toPng(scale = 2) {
      const source = canvasRef.current;
      if (source == null) return null;
      const out = document.createElement('canvas');
      out.width = Math.floor(width * scale);
      out.height = Math.floor(height * scale);
      const ctx = out.getContext('2d');
      if (ctx == null) return null;
      ctx.setTransform(scale, 0, 0, scale, 0, 0);
      paintBackground(ctx, template, width, height);
      for (const stroke of strokes.current) drawStroke(ctx, stroke);
      return await new Promise<Blob | null>((resolve) => {
        if (typeof out.toBlob !== 'function') {
          resolve(null);
          return;
        }
        out.toBlob((blob) => resolve(blob), 'image/png');
      });
    },
  }));

  const point = (event: React.PointerEvent<HTMLCanvasElement>): [number, number, number] => {
    const rect = event.currentTarget.getBoundingClientRect();
    return [Math.round((event.clientX - rect.left) * 100) / 100, Math.round((event.clientY - rect.top) * 100) / 100, Math.round((event.pressure || 0.5) * 100) / 100];
  };

  const ignore = (event: React.PointerEvent<HTMLCanvasElement>): boolean => {
    if (event.pointerType === 'pen') {
      penSeen.current = true;
      return false;
    }
    return penSeen.current && event.pointerType === 'touch';
  };

  const down = (event: React.PointerEvent<HTMLCanvasElement>): void => {
    if (ignore(event)) return;
    event.currentTarget.setPointerCapture(event.pointerId);
    drawing.current = { tool, color, width: WIDTHS[tool], points: [point(event)] };
    if (pauseTimer.current !== null) window.clearTimeout(pauseTimer.current);
  };

  const move = (event: React.PointerEvent<HTMLCanvasElement>): void => {
    if (drawing.current === null || ignore(event)) return;
    drawing.current.points.push(point(event));
    repaint();
  };

  const up = (): void => {
    if (drawing.current === null) return;
    if (drawing.current.points.length > 1) {
      strokes.current.push(drawing.current);
      undone.current = [];
      onChange?.(strokes.current);
    }
    drawing.current = null;
    repaint();
    force((n) => n + 1);
    if (onPause !== undefined) {
      if (pauseTimer.current !== null) window.clearTimeout(pauseTimer.current);
      pauseTimer.current = window.setTimeout(() => {
        pauseTimer.current = null;
        onPause();
      }, PAUSE_MS);
    }
  };

  useEffect(
    () => () => {
      if (pauseTimer.current !== null) window.clearTimeout(pauseTimer.current);
    },
    [],
  );

  return (
    <Box
      component="canvas"
      ref={canvasRef}
      role="img"
      aria-label={ariaLabel}
      onPointerDown={down}
      onPointerMove={move}
      onPointerUp={up}
      onPointerCancel={up}
      onPointerLeave={up}
      sx={{ width, height, touchAction: 'none', cursor: 'crosshair', border: 1, borderColor: 'divider', borderRadius: 1, bgcolor: 'common.white', display: 'block' }}
    />
  );
});

function drawStroke(ctx: CanvasRenderingContext2D, stroke: Stroke): void {
  if (stroke.points.length === 0) return;
  ctx.save();
  ctx.lineCap = 'round';
  ctx.lineJoin = 'round';
  ctx.globalCompositeOperation = stroke.tool === 'eraser' ? 'destination-out' : 'source-over';
  ctx.strokeStyle = stroke.color;
  ctx.globalAlpha = stroke.tool === 'marker' ? 0.45 : 1;
  const first = stroke.points[0];
  if (first === undefined) {
    ctx.restore();
    return;
  }
  for (let i = 1; i < stroke.points.length; i++) {
    const a = stroke.points[i - 1];
    const b = stroke.points[i];
    if (a === undefined || b === undefined) continue;
    ctx.beginPath();
    ctx.lineWidth = stroke.width * (0.5 + (b[2] || 0.5));
    ctx.moveTo(a[0], a[1]);
    ctx.lineTo(b[0], b[1]);
    ctx.stroke();
  }
  ctx.restore();
}

export default StrokeCanvas;
