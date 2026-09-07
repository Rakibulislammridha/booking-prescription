// The table under every chart. Deliberately plain MUI (the Pro grid is not licensed, CONVENTIONS §7.3) and
// horizontally scrollable, because a 24-column heatmap must not make the whole panel scroll sideways.
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import Typography from '@mui/material/Typography';

export interface Column<T> {
  key: string;
  label: string;
  align?: 'left' | 'right';
  /** Bangla names need `lang="bn"` so the font stack picks Noto Sans Bengali. */
  bn?: boolean;
  render: (row: T) => ReactNode;
}

export interface DataTableProps<T> {
  columns: Column<T>[];
  rows: T[];
  rowKey: (row: T, index: number) => string;
  dense?: boolean;
  emptyLabel?: string;
}

export function DataTable<T>({ columns, rows, rowKey, dense = true, emptyLabel }: DataTableProps<T>) {
  const { t } = useTranslation();

  return (
    <Box sx={{ overflowX: 'auto' }}>
      <Table size={dense ? 'small' : 'medium'}>
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
                <Typography variant="body2" color="text.secondary" sx={{ py: 3, textAlign: 'center' }}>
                  {emptyLabel ?? t('reports.empty')}
                </Typography>
              </TableCell>
            </TableRow>
          ) : rows.map((row, index) => (
            <TableRow key={rowKey(row, index)} hover>
              {columns.map((column) => (
                <TableCell
                  key={column.key}
                  align={column.align ?? 'left'}
                  lang={column.bn ? 'bn' : undefined}
                  sx={column.align === 'right' ? { fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' } : undefined}
                >
                  {column.render(row)}
                </TableCell>
              ))}
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </Box>
  );
}
