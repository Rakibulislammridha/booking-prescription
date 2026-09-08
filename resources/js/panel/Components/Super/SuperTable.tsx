// The console's table. Deliberately plain MUI (the Pro grid is not licensed, CONVENTIONS §7.3), compact by
// default, horizontally scrollable so a wide row never makes the page scroll sideways, and always carrying an
// empty state — an operations screen that renders nothing at all is indistinguishable from one that is broken.
import type { ReactNode } from 'react';
import Box from '@mui/material/Box';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import Typography from '@mui/material/Typography';

export interface SuperColumn<T> {
  key: string;
  label: string;
  align?: 'left' | 'right';
  /** Bangla clinic and doctor names need `lang="bn"` so the font stack picks Noto Sans Bengali. */
  bn?: boolean;
  render: (row: T, index: number) => ReactNode;
}

export interface SuperTableProps<T> {
  columns: SuperColumn<T>[];
  rows: T[];
  rowKey: (row: T, index: number) => string;
  /** Empty-state text; already translated by the caller. */
  empty: string;
  label?: string;
  dense?: boolean;
  onRowClick?: (row: T) => void;
}

export function SuperTable<T>({ columns, rows, rowKey, empty, label, dense = true, onRowClick }: SuperTableProps<T>) {
  return (
    <Box sx={{ overflowX: 'auto' }}>
      <Table size={dense ? 'small' : 'medium'} aria-label={label}>
        <TableHead>
          <TableRow>
            {columns.map((column) => (
              <TableCell key={column.key} align={column.align ?? 'left'} sx={{ whiteSpace: 'nowrap' }}>{column.label}</TableCell>
            ))}
          </TableRow>
        </TableHead>
        <TableBody>
          {rows.length === 0 ? (
            <TableRow>
              <TableCell colSpan={Math.max(1, columns.length)}>
                <Typography variant="body2" color="text.secondary" sx={{ py: 3, textAlign: 'center' }}>{empty}</Typography>
              </TableCell>
            </TableRow>
          ) : rows.map((row, index) => (
            <TableRow
              key={rowKey(row, index)}
              hover
              tabIndex={onRowClick ? 0 : undefined}
              onClick={onRowClick ? () => onRowClick(row) : undefined}
              onKeyDown={onRowClick ? (e) => { if (e.key === 'Enter') onRowClick(row); } : undefined}
              sx={onRowClick ? { cursor: 'pointer' } : undefined}
            >
              {columns.map((column) => (
                <TableCell
                  key={column.key}
                  align={column.align ?? 'left'}
                  lang={column.bn ? 'bn' : undefined}
                  sx={column.align === 'right' ? { fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' } : undefined}
                >
                  {column.render(row, index)}
                </TableCell>
              ))}
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </Box>
  );
}
