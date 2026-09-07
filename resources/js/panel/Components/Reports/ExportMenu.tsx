// CSV / Excel / PDF for the report on screen (BRIEF §5.L). The link carries the CURRENT filters, so the file
// is the table the user is looking at — the server builds it from the same payload the page was rendered from.
// Hidden entirely without `reports.export`: reading a number and walking out with the file are different
// permissions.
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import Button from '@mui/material/Button';
import Menu from '@mui/material/Menu';
import MenuItem from '@mui/material/MenuItem';
import ListItemIcon from '@mui/material/ListItemIcon';
import ListItemText from '@mui/material/ListItemText';
import DownloadIcon from '@mui/icons-material/Download';
import TableChartIcon from '@mui/icons-material/TableChart';
import GridOnIcon from '@mui/icons-material/GridOn';
import PictureAsPdfIcon from '@mui/icons-material/PictureAsPdf';
import { route } from '@shared/routes';
import type { ReportFormat, ReportKind } from '@shared/types/models';

const FORMATS: { format: ReportFormat; icon: typeof TableChartIcon }[] = [
  { format: 'csv', icon: TableChartIcon },
  { format: 'xlsx', icon: GridOnIcon },
  { format: 'pdf', icon: PictureAsPdfIcon },
];

export interface ExportMenuProps {
  report: ReportKind;
  query: Record<string, string | number | null | undefined>;
  disabled?: boolean;
}

export function ExportMenu({ report, query, disabled = false }: ExportMenuProps) {
  const { t } = useTranslation();
  const [anchor, setAnchor] = useState<HTMLElement | null>(null);

  const href = (format: ReportFormat): string => {
    const params = new URLSearchParams({ format });
    for (const [key, value] of Object.entries(query)) {
      if (key !== 'report' && value !== null && value !== undefined && value !== '') params.set(key, String(value));
    }
    return `${route('panel.reports.export', { report })}?${params.toString()}`;
  };

  return (
    <>
      <Button size="small" startIcon={<DownloadIcon />} disabled={disabled} onClick={(e) => setAnchor(e.currentTarget)} aria-haspopup="menu">
        {t('reports.export.label')}
      </Button>
      <Menu anchorEl={anchor} open={Boolean(anchor)} onClose={() => setAnchor(null)}>
        {FORMATS.map(({ format, icon: Icon }) => (
          <MenuItem key={format} component="a" href={href(format)} onClick={() => setAnchor(null)}>
            <ListItemIcon><Icon fontSize="small" /></ListItemIcon>
            <ListItemText primary={t(`reports.export.${format}`)} />
          </MenuItem>
        ))}
      </Menu>
    </>
  );
}
