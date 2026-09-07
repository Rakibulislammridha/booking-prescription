// Commission RULES (BRIEF §5.I). Changing one never rewrites a historical split: every issued invoice item
// carries its own frozen doctor/clinic amounts.
import { useState, type ReactNode } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { route } from '@shared/routes';
import { formatBdt } from '@shared/format/money';
import { getLocale } from '@shared/locale';
import type { PageProps } from '@shared/types/inertia';
import type { BillingRevenueShareRule, RevenueShareItemType, RevenueShareType } from '@shared/types/models';

type Props = PageProps<{
  shares: BillingRevenueShareRule[];
  doctors: Array<{ public_id: string; name: string }>;
  branch_options: Array<{ public_id: string; name: string }>;
  item_types: RevenueShareItemType[];
  share_types: RevenueShareType[];
  can: { manage: boolean };
}>;

export default function RevenueShares({ shares, doctors, branch_options, item_types, share_types, can }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [open, setOpen] = useState(false);
  const today = new Date().toISOString().slice(0, 10);
  const form = useForm({ doctor: '', branch: null as string | null, item_type: 'all' as RevenueShareItemType, share_type: 'percentage' as RevenueShareType, share_value: '', effective_from: today, effective_to: null as string | null, is_active: true });

  const valid = form.data.doctor !== '' && form.data.share_value.trim() !== '';

  return (
    <Stack spacing={2}>
      <Stack direction="row" spacing={2} sx={{ alignItems: 'center' }}>
        <Typography variant="h5" component="h1" sx={{ flexGrow: 1 }}>{t('billing.shares.title')}</Typography>
        {can.manage ? <Button variant="contained" onClick={() => setOpen(true)}>{t('billing.shares.new')}</Button> : null}
      </Stack>

      <Alert severity="info">{t('billing.shares.frozen_notice')}</Alert>

      <Card variant="outlined">
        <CardContent>
          <Box sx={{ overflowX: 'auto' }}>
            <Table size="small">
              <TableHead><TableRow><TableCell>{t('billing.shares.doctor')}</TableCell><TableCell>{t('billing.shares.branch')}</TableCell><TableCell>{t('billing.shares.item_type')}</TableCell><TableCell>{t('billing.shares.share')}</TableCell><TableCell>{t('billing.shares.effective')}</TableCell><TableCell>{t('billing.shares.state')}</TableCell></TableRow></TableHead>
              <TableBody>
                {shares.length === 0 ? (
                  <TableRow><TableCell colSpan={6}><Typography variant="body2" color="text.secondary" sx={{ py: 2, textAlign: 'center' }}>{t('billing.shares.empty')}</Typography></TableCell></TableRow>
                ) : shares.map((share) => (
                  <TableRow key={share.id}>
                    <TableCell lang="bn">{share.doctor?.name ?? '—'}</TableCell>
                    <TableCell lang="bn">{share.branch?.name ?? t('billing.shares.all_branches')}</TableCell>
                    <TableCell>{t(`billing.item_type.${share.item_type}`)}</TableCell>
                    <TableCell>{share.share_type === 'percentage' ? `${share.share_value}%` : formatBdt(Math.round(Number(share.share_value) * 100), locale)}</TableCell>
                    <TableCell>{share.effective_from}{share.effective_to ? ` → ${share.effective_to}` : ''}</TableCell>
                    <TableCell><Chip size="small" color={share.is_active ? 'success' : 'default'} label={t(share.is_active ? 'billing.shares.active' : 'billing.shares.inactive')} /></TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Box>
        </CardContent>
      </Card>

      <Dialog open={open} onClose={form.processing ? undefined : () => setOpen(false)} fullWidth maxWidth="xs" onKeyDown={(e) => { if (e.key === 'Enter' && valid) submit(); }}>
        <DialogTitle>{t('billing.shares.new')}</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ pt: 1 }}>
            <TextField select autoFocus label={t('billing.shares.doctor')} value={form.data.doctor} onChange={(e) => form.setData('doctor', e.target.value)} error={Boolean(form.errors.doctor)} helperText={form.errors.doctor ?? ' '}>
              {doctors.map((d) => <MenuItem key={d.public_id} value={d.public_id}>{d.name}</MenuItem>)}
            </TextField>
            <TextField select label={t('billing.shares.branch')} value={form.data.branch ?? ''} onChange={(e) => form.setData('branch', e.target.value || null)}>
              <MenuItem value="">{t('billing.shares.all_branches')}</MenuItem>
              {branch_options.map((b) => <MenuItem key={b.public_id} value={b.public_id}>{b.name}</MenuItem>)}
            </TextField>
            <TextField select label={t('billing.shares.item_type')} value={form.data.item_type} onChange={(e) => form.setData('item_type', e.target.value as RevenueShareItemType)}>
              {item_types.map((type) => <MenuItem key={type} value={type}>{t(`billing.item_type.${type}`)}</MenuItem>)}
            </TextField>
            <TextField select label={t('billing.shares.share_type')} value={form.data.share_type} onChange={(e) => form.setData('share_type', e.target.value as RevenueShareType)}>
              {share_types.map((type) => <MenuItem key={type} value={type}>{t(`billing.discount.type_${type}`)}</MenuItem>)}
            </TextField>
            <TextField label={t('billing.shares.share')} value={form.data.share_value} onChange={(e) => form.setData('share_value', e.target.value)} slotProps={{ htmlInput: { inputMode: 'decimal' } }} error={Boolean(form.errors.share_value)} helperText={form.errors.share_value ?? ' '} />
            <TextField type="date" label={t('billing.shares.effective_from')} value={form.data.effective_from} onChange={(e) => form.setData('effective_from', e.target.value)} slotProps={{ inputLabel: { shrink: true } }} />
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setOpen(false)} disabled={form.processing}>{t('common.actions.cancel')}</Button>
          <Button variant="contained" disabled={form.processing || !valid} onClick={submit}>{t('common.actions.save')}</Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );

  function submit() {
    form.post(route('panel.billing.revenue_shares.store'), { onSuccess: () => { setOpen(false); form.reset(); } });
  }
}

RevenueShares.layout = (page: ReactNode) => <PanelLayout title="billing.shares.title">{page}</PanelLayout>;
