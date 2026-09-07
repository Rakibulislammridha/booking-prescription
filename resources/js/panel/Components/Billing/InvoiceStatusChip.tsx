// One chip, one meaning: a bill is either still owed, settled, or void.
import Chip from '@mui/material/Chip';
import { useTranslation } from 'react-i18next';
import type { InvoiceStatus } from '@shared/types/models';

export interface InvoiceStatusChipProps {
  status: InvoiceStatus;
}

const COLOR: Record<InvoiceStatus, 'default' | 'info' | 'warning' | 'success' | 'error'> = {
  draft: 'default',
  issued: 'info',
  partially_paid: 'warning',
  paid: 'success',
  void: 'default',
  refunded: 'error',
};

export function InvoiceStatusChip({ status }: InvoiceStatusChipProps) {
  const { t } = useTranslation();

  return <Chip size="small" color={COLOR[status]} label={t(`billing.status.${status}`)} />;
}
