// Coupon management (BRIEF §5.I). A coupon that has discounted a real bill is deactivated, never deleted.
import { useState, type ReactNode } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
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
import type { BillingCoupon, DiscountType, Paginated } from '@shared/types/models';

type Props = PageProps<{ coupons: Paginated<BillingCoupon>; types: DiscountType[]; can: { manage: boolean } }>;

export default function Coupons({ coupons, types, can }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [open, setOpen] = useState(false);
  const form = useForm({ code: '', name: '', type: 'percentage' as DiscountType, value: '', max_discount_paisa: null as number | null, min_invoice_paisa: 0, max_uses: null as number | null, max_uses_per_patient: 1, is_active: true });

  const valid = form.data.code.trim() !== '' && form.data.name.trim() !== '' && form.data.value.trim() !== '';

  return (
    <Stack spacing={2}>
      <Stack direction="row" spacing={2} sx={{ alignItems: 'center' }}>
        <Typography variant="h5" component="h1" sx={{ flexGrow: 1 }}>{t('billing.coupons.title')}</Typography>
        {can.manage ? <Button variant="contained" onClick={() => setOpen(true)}>{t('billing.coupons.new')}</Button> : null}
      </Stack>

      <Card variant="outlined">
        <CardContent>
          <Box sx={{ overflowX: 'auto' }}>
            <Table size="small">
              <TableHead><TableRow><TableCell>{t('billing.coupons.code')}</TableCell><TableCell>{t('billing.coupons.name')}</TableCell><TableCell>{t('billing.coupons.value')}</TableCell><TableCell align="right">{t('billing.coupons.uses')}</TableCell><TableCell>{t('billing.coupons.state')}</TableCell><TableCell /></TableRow></TableHead>
              <TableBody>
                {coupons.data.length === 0 ? (
                  <TableRow><TableCell colSpan={6}><Typography variant="body2" color="text.secondary" sx={{ py: 2, textAlign: 'center' }}>{t('billing.coupons.empty')}</Typography></TableCell></TableRow>
                ) : coupons.data.map((coupon) => (
                  <TableRow key={coupon.id}>
                    <TableCell><strong>{coupon.code}</strong></TableCell>
                    <TableCell lang="bn">{coupon.name}</TableCell>
                    <TableCell>{coupon.type === 'percentage' ? `${coupon.value}%` : formatBdt(Math.round(Number(coupon.value) * 100), locale)}</TableCell>
                    <TableCell align="right">{coupon.uses_count}{coupon.max_uses === null ? '' : ` / ${coupon.max_uses}`}</TableCell>
                    <TableCell><Chip size="small" color={coupon.is_active ? 'success' : 'default'} label={t(coupon.is_active ? 'billing.coupons.active' : 'billing.coupons.inactive')} /></TableCell>
                    <TableCell align="right">
                      {can.manage && coupon.is_active ? (
                        <Button size="small" color="error" onClick={() => router_delete(coupon.id)}>{t('billing.coupons.deactivate')}</Button>
                      ) : null}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Box>
        </CardContent>
      </Card>

      <Dialog open={open} onClose={form.processing ? undefined : () => setOpen(false)} fullWidth maxWidth="xs" onKeyDown={(e) => { if (e.key === 'Enter' && valid) submit(); }}>
        <DialogTitle>{t('billing.coupons.new')}</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ pt: 1 }}>
            <TextField autoFocus label={t('billing.coupons.code')} value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} error={Boolean(form.errors.code)} helperText={form.errors.code ?? ' '} />
            <TextField label={t('billing.coupons.name')} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} error={Boolean(form.errors.name)} helperText={form.errors.name ?? ' '} slotProps={{ htmlInput: { lang: 'bn' } }} />
            <TextField select label={t('billing.coupons.type')} value={form.data.type} onChange={(e) => form.setData('type', e.target.value as DiscountType)}>
              {types.map((type) => <MenuItem key={type} value={type}>{t(`billing.discount.type_${type}`)}</MenuItem>)}
            </TextField>
            <TextField label={t('billing.coupons.value')} value={form.data.value} onChange={(e) => form.setData('value', e.target.value)} slotProps={{ htmlInput: { inputMode: 'decimal' } }} error={Boolean(form.errors.value)} helperText={form.errors.value ?? ' '} />
            <TextField label={t('billing.coupons.max_uses')} value={form.data.max_uses ?? ''} onChange={(e) => form.setData('max_uses', e.target.value === '' ? null : Number(e.target.value))} slotProps={{ htmlInput: { inputMode: 'numeric' } }} />
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
    form.post(route('panel.billing.coupons.store'), { onSuccess: () => { setOpen(false); form.reset(); } });
  }

  function router_delete(id: number) {
    form.delete(route('panel.billing.coupons.destroy', { coupon: id }), { preserveScroll: true });
  }
}

Coupons.layout = (page: ReactNode) => <PanelLayout title="billing.coupons.title">{page}</PanelLayout>;
