// The drag-reorder list of a session (CONVENTIONS §7.3: @dnd-kit lives only here and in the pad designer).
// The request carries the neighbours' public_ids, never indexes, so a stale board cannot drop a serial into the
// wrong place — the server answers 409 serials.reorder_stale and the caller reloads (SERIAL_ENGINE §7.2).
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { DndContext, KeyboardSensor, PointerSensor, closestCenter, useSensor, useSensors, type DragEndEvent } from '@dnd-kit/core';
import { SortableContext, arrayMove, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import List from '@mui/material/List';
import ListItem from '@mui/material/ListItem';
import ListItemText from '@mui/material/ListItemText';
import IconButton from '@mui/material/IconButton';
import Chip from '@mui/material/Chip';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import DragIndicatorIcon from '@mui/icons-material/DragIndicator';
import { formatTimeDhaka } from '@shared/format/date';
import type { Serial, SerialPriority, SerialStatus } from '@shared/types/models';

export interface QueueListProps {
  serials: Serial[];
  canReorder: boolean;
  /** Called with the moved serial and its new neighbours (public ids); resolve = success, reject = reload. */
  onReorder: (serial: Serial, after: string | null, before: string | null) => Promise<void>;
  renderActions?: (serial: Serial) => React.ReactNode;
}

const STATUS_COLOR: Record<SerialStatus, 'default' | 'info' | 'warning' | 'success' | 'error' | 'secondary'> = {
  booked: 'default',
  checked_in: 'info',
  in_consultation: 'warning',
  completed: 'success',
  no_show: 'error',
  cancelled: 'secondary',
  postponed: 'secondary',
};

const PRIORITY_COLOR: Record<SerialPriority, 'default' | 'error' | 'secondary' | 'warning'> = {
  normal: 'default',
  emergency: 'error',
  vip: 'secondary',
  elderly: 'warning',
};

export function isActiveSerial(serial: Serial): boolean {
  return serial.status === 'booked' || serial.status === 'checked_in' || serial.status === 'in_consultation';
}

function Row({ serial, canDrag, actions }: { serial: Serial; canDrag: boolean; actions?: React.ReactNode }) {
  const { t } = useTranslation();
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: serial.public_id, disabled: !canDrag });
  const movable = canDrag && (serial.status === 'booked' || serial.status === 'checked_in');

  return (
    <ListItem
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      sx={{ opacity: isDragging ? 0.6 : 1, bgcolor: serial.status === 'in_consultation' ? 'action.selected' : 'background.paper', borderBottom: 1, borderColor: 'divider', gap: 1 }}
      secondaryAction={actions}
      data-testid={`queue-row-${serial.display_code}`}
    >
      <IconButton
        size="small"
        aria-label={t('serials.queue.drag_handle', { code: serial.display_code })}
        disabled={!movable}
        {...attributes}
        {...listeners}
        sx={{ cursor: movable ? 'grab' : 'default', touchAction: 'none' }}
      >
        <DragIndicatorIcon fontSize="small" />
      </IconButton>
      <ListItemText
        primary={
          <Stack direction="row" spacing={1} sx={{ alignItems: 'center', flexWrap: 'wrap' }}>
            <Typography variant="subtitle1" component="span" sx={{ fontWeight: 700, minWidth: 64 }}>{serial.display_code}</Typography>
            <Chip size="small" label={t(`serials.status.${serial.status}`)} color={STATUS_COLOR[serial.status]} />
            {serial.priority !== 'normal' ? <Chip size="small" variant="outlined" label={t(`serials.priority.${serial.priority}`)} color={PRIORITY_COLOR[serial.priority]} /> : null}
            <Chip size="small" variant="outlined" label={t(`serials.source.${serial.source}`)} />
          </Stack>
        }
        secondary={[
          serial.checked_in_at ? `${t('serials.queue.checked_in_at')} ${formatTimeDhaka(serial.checked_in_at)}` : null,
          serial.called_at ? `${t('serials.queue.called_at')} ${formatTimeDhaka(serial.called_at)}` : null,
          serial.passed_count > 0 ? t('serials.queue.passed', { count: serial.passed_count }) : null,
        ].filter(Boolean).join(' · ')}
      />
    </ListItem>
  );
}

export function QueueList({ serials, canReorder, onReorder, renderActions }: QueueListProps) {
  const { t } = useTranslation();
  const [items, setItems] = useState<Serial[]>(serials);
  useEffect(() => setItems(serials), [serials]);

  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 4 } }), useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }));
  const active = items.filter(isActiveSerial);
  const terminal = items.filter((s) => !isActiveSerial(s));

  const handleDragEnd = async (event: DragEndEvent): Promise<void> => {
    const { active: dragged, over } = event;
    if (!over || dragged.id === over.id) return;
    const from = active.findIndex((s) => s.public_id === dragged.id);
    const to = active.findIndex((s) => s.public_id === over.id);
    if (from < 0 || to < 0) return;
    const moved = active[from];
    if (!moved) return;
    const next = arrayMove(active, from, to);
    const idx = next.findIndex((s) => s.public_id === moved.public_id);
    const after = next[idx - 1]?.public_id ?? null;
    const before = next[idx + 1]?.public_id ?? null;
    setItems([...next, ...terminal]);
    try {
      await onReorder(moved, after, before);
    } catch {
      setItems(serials); // stale → the caller reloads; restore the server order meanwhile
    }
  };

  if (items.length === 0) {
    return <Typography variant="body2" color="text.secondary" sx={{ p: 2 }}>{t('serials.queue.empty')}</Typography>;
  }

  return (
    <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={(e) => void handleDragEnd(e)}>
      <SortableContext items={active.map((s) => s.public_id)} strategy={verticalListSortingStrategy}>
        <List disablePadding aria-label={t('serials.queue.title')}>
          {active.map((serial) => <Row key={serial.public_id} serial={serial} canDrag={canReorder} actions={renderActions?.(serial)} />)}
        </List>
      </SortableContext>
      {terminal.length > 0 ? (
        <List disablePadding dense aria-label={t('serials.queue.finished')} sx={{ opacity: 0.7 }}>
          {terminal.map((serial) => <Row key={serial.public_id} serial={serial} canDrag={false} actions={renderActions?.(serial)} />)}
        </List>
      ) : null}
    </DndContext>
  );
}

export default QueueList;
