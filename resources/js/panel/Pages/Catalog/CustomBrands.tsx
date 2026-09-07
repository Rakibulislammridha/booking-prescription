// Tenant custom brands (CATALOG.md §8): a plain table plus a create/edit form whose generic picker autocompletes
// against GET /api/catalog/generics. A brand cannot be saved without a molecule (BRIEF §3.4).
import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { router, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Autocomplete from '@mui/material/Autocomplete';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import IconButton from '@mui/material/IconButton';
import MenuItem from '@mui/material/MenuItem';
import Paper from '@mui/material/Paper';
import Stack from '@mui/material/Stack';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableContainer from '@mui/material/TableContainer';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import AddIcon from '@mui/icons-material/Add';
import EditIcon from '@mui/icons-material/Edit';
import DeleteIcon from '@mui/icons-material/DeleteOutlined';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { useSharedProps } from '@shared/inertia';
import { route } from '@shared/routes';
import { formatBn } from '@shared/format/number';
import type { PageProps } from '@shared/types/inertia';
import type { CustomBrand, CustomBrandReviewStatus, DosageFormOption, GenericOption, Paginated, RouteOption } from '@shared/types/models';
import { searchGenerics } from './api';

type Props = PageProps<{
  brands: Paginated<CustomBrand>;
  forms: DosageFormOption[];
  routes: RouteOption[];
  can: { create: boolean; delete: boolean };
}>;

interface FormValues {
  brand_name: string;
  generic_id: number | '';
  manufacturer: string;
  strength: string;
  dosage_form_id: number | '';
  route_id: number | '';
}

const EMPTY: FormValues = { brand_name: '', generic_id: '', manufacturer: '', strength: '', dosage_form_id: '', route_id: '' };

const STATUS_COLOR: Record<CustomBrandReviewStatus, 'default' | 'success' | 'warning' | 'info'> = {
  pending: 'warning',
  approved: 'info',
  rejected: 'default',
  promoted: 'success',
};

function useGenericOptions(query: string, seed: GenericOption | null): { options: GenericOption[]; loading: boolean } {
  const [options, setOptions] = useState<GenericOption[]>(seed ? [seed] : []);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (query.trim().length < 2) {
      setOptions(seed ? [seed] : []);
      return;
    }
    const controller = new AbortController();
    const timer = window.setTimeout(() => {
      setLoading(true);
      searchGenerics(query, controller.signal)
        .then((hits) => setOptions(seed && !hits.some((h) => h.id === seed.id) ? [seed, ...hits] : hits))
        .catch(() => undefined)
        .finally(() => setLoading(false));
    }, 200);
    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [query, seed]);

  return { options, loading };
}

function BrandDialog({ open, brand, forms, routes, onClose }: { open: boolean; brand: CustomBrand | null; forms: DosageFormOption[]; routes: RouteOption[]; onClose: () => void }) {
  const { t } = useTranslation();
  const { locale } = useSharedProps();
  const seed = useMemo<GenericOption | null>(
    () => (brand ? { id: brand.generic_id, name: brand.generic_name, name_bn: null, therapeutic_class: null, aliases: [] } : null),
    [brand],
  );
  const [generic, setGeneric] = useState<GenericOption | null>(seed);
  const [genericQuery, setGenericQuery] = useState('');
  const { options, loading } = useGenericOptions(genericQuery, seed);
  const form = useForm<FormValues>(EMPTY);

  useEffect(() => {
    if (!open) return;
    setGeneric(seed);
    setGenericQuery('');
    form.setData(
      brand
        ? {
            brand_name: brand.brand_name,
            generic_id: brand.generic_id,
            manufacturer: brand.manufacturer ?? '',
            strength: brand.strength ?? '',
            dosage_form_id: brand.dosage_form_id ?? '',
            route_id: brand.route_id ?? '',
          }
        : EMPTY,
    );
    form.clearErrors();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, brand, seed]);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    const options = { preserveScroll: true, onSuccess: () => onClose() };
    if (brand) form.put(route('panel.catalog.custom-brands.update', { customBrand: brand.id }), options);
    else form.post(route('panel.catalog.custom-brands.store'), options);
  };

  const label = (o: { name: string; name_bn: string | null }) => (locale === 'bn' && o.name_bn ? `${o.name_bn} (${o.name})` : o.name);

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm">
      <Box component="form" onSubmit={submit} noValidate>
        <DialogTitle>{brand ? t('catalog.custom_brands.actions.edit') : t('catalog.custom_brands.actions.add')}</DialogTitle>
        <DialogContent sx={{ display: 'grid', gap: 2, pt: 1 }}>
          <TextField
            label={t('catalog.custom_brands.fields.brand_name')}
            value={form.data.brand_name}
            onChange={(e) => form.setData('brand_name', e.target.value)}
            error={Boolean(form.errors.brand_name)}
            helperText={form.errors.brand_name}
            required
            autoFocus
            slotProps={{ htmlInput: { maxLength: 160 } }}
          />
          <Autocomplete<GenericOption, false, false, false>
            options={options}
            value={generic}
            loading={loading}
            getOptionLabel={label}
            isOptionEqualToValue={(a, b) => a.id === b.id}
            filterOptions={(x) => x}
            noOptionsText={t('catalog.custom_brands.no_generic_matches')}
            onInputChange={(_, value, reason) => {
              if (reason === 'input') setGenericQuery(value);
            }}
            onChange={(_, value) => {
              setGeneric(value);
              form.setData('generic_id', value ? value.id : '');
            }}
            renderOption={(props, option) => (
              <li {...props} key={option.id}>
                <Box>
                  <Typography variant="body2">{label(option)}</Typography>
                  {option.therapeutic_class && <Typography variant="caption" color="text.secondary">{option.therapeutic_class}</Typography>}
                </Box>
              </li>
            )}
            renderInput={(params) => (
              <TextField
                {...params}
                required
                label={t('catalog.custom_brands.fields.generic')}
                error={Boolean(form.errors.generic_id)}
                helperText={form.errors.generic_id ?? t('catalog.custom_brands.fields.generic_help')}
              />
            )}
          />
          <TextField
            label={t('catalog.custom_brands.fields.manufacturer')}
            value={form.data.manufacturer}
            onChange={(e) => form.setData('manufacturer', e.target.value)}
            error={Boolean(form.errors.manufacturer)}
            helperText={form.errors.manufacturer}
            slotProps={{ htmlInput: { maxLength: 160 } }}
          />
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
            <TextField
              label={t('catalog.custom_brands.fields.strength')}
              placeholder={t('catalog.custom_brands.fields.strength_placeholder')}
              value={form.data.strength}
              onChange={(e) => form.setData('strength', e.target.value)}
              error={Boolean(form.errors.strength)}
              helperText={form.errors.strength}
              slotProps={{ htmlInput: { maxLength: 64 } }}
              sx={{ flex: 1 }}
            />
            <TextField
              select
              label={t('catalog.custom_brands.fields.form')}
              value={form.data.dosage_form_id}
              onChange={(e) => form.setData('dosage_form_id', e.target.value === '' ? '' : Number(e.target.value))}
              error={Boolean(form.errors.dosage_form_id)}
              helperText={form.errors.dosage_form_id}
              sx={{ flex: 1 }}
            >
              <MenuItem value="">—</MenuItem>
              {forms.map((f) => (
                <MenuItem key={f.id} value={f.id}>{label(f)}</MenuItem>
              ))}
            </TextField>
            <TextField
              select
              label={t('catalog.custom_brands.fields.route')}
              value={form.data.route_id}
              onChange={(e) => form.setData('route_id', e.target.value === '' ? '' : Number(e.target.value))}
              error={Boolean(form.errors.route_id)}
              helperText={form.errors.route_id}
              sx={{ flex: 1 }}
            >
              <MenuItem value="">—</MenuItem>
              {routes.map((r) => (
                <MenuItem key={r.id} value={r.id}>{label(r)}</MenuItem>
              ))}
            </TextField>
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose}>{t('common.actions.cancel')}</Button>
          <Button type="submit" variant="contained" disabled={form.processing || form.data.generic_id === ''}>{t('common.actions.save')}</Button>
        </DialogActions>
      </Box>
    </Dialog>
  );
}

export default function CustomBrands({ brands, forms, routes, can }: Props) {
  const { t } = useTranslation();
  const { locale, flash } = useSharedProps();
  const [dialog, setDialog] = useState<{ open: boolean; brand: CustomBrand | null }>({ open: false, brand: null });
  const n = (value: number) => (locale === 'bn' ? formatBn(value) : String(value));

  const remove = (brand: CustomBrand) => {
    if (window.confirm(t('catalog.custom_brands.actions.delete_confirm', { name: brand.brand_name }))) {
      router.delete(route('panel.catalog.custom-brands.destroy', { customBrand: brand.id }), { preserveScroll: true });
    }
  };

  return (
    <Box sx={{ display: 'grid', gap: 2 }}>
      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} sx={{ alignItems: { sm: 'center' }, justifyContent: 'space-between' }}>
        <Typography variant="body2" color="text.secondary" sx={{ maxWidth: 720 }}>{t('catalog.custom_brands.intro')}</Typography>
        {can.create && (
          <Button variant="contained" startIcon={<AddIcon />} onClick={() => setDialog({ open: true, brand: null })}>
            {t('catalog.custom_brands.actions.add')}
          </Button>
        )}
      </Stack>
      {flash.success && <Alert severity="success">{flash.success}</Alert>}
      {flash.error && <Alert severity="error">{flash.error}</Alert>}
      <TableContainer component={Paper}>
        <Table size="small" aria-label={t('catalog.custom_brands.title')}>
          <TableHead>
            <TableRow>
              <TableCell>{t('catalog.custom_brands.fields.brand_name')}</TableCell>
              <TableCell>{t('catalog.custom_brands.fields.generic')}</TableCell>
              <TableCell>{t('catalog.custom_brands.fields.strength')}</TableCell>
              <TableCell>{t('catalog.custom_brands.fields.form')}</TableCell>
              <TableCell>{t('catalog.custom_brands.fields.manufacturer')}</TableCell>
              <TableCell>{t('catalog.custom_brands.fields.status')}</TableCell>
              <TableCell align="right">{t('catalog.custom_brands.fields.use_count')}</TableCell>
              <TableCell align="right" />
            </TableRow>
          </TableHead>
          <TableBody>
            {brands.data.length === 0 && (
              <TableRow>
                <TableCell colSpan={8}>
                  <Typography variant="body2" color="text.secondary">{t('catalog.custom_brands.empty')}</Typography>
                </TableCell>
              </TableRow>
            )}
            {brands.data.map((b) => (
              <TableRow key={b.id} hover sx={{ opacity: b.is_active ? 1 : 0.6 }}>
                <TableCell>
                  <Typography variant="body2" sx={{ fontWeight: 600 }}>{b.brand_name}</Typography>
                  {!b.is_active && <Chip size="small" label={t('catalog.custom_brands.inactive')} sx={{ ml: 1 }} />}
                </TableCell>
                <TableCell>{b.generic_name}</TableCell>
                <TableCell>{b.strength ?? '—'}</TableCell>
                <TableCell>{b.form ?? '—'}</TableCell>
                <TableCell>{b.manufacturer ?? '—'}</TableCell>
                <TableCell>
                  <Chip size="small" color={STATUS_COLOR[b.review_status]} label={t(`catalog.custom_brands.status.${b.review_status}`)} title={b.review_note ?? undefined} />
                </TableCell>
                <TableCell align="right">{n(b.use_count)}</TableCell>
                <TableCell align="right" sx={{ whiteSpace: 'nowrap' }}>
                  {can.create && b.review_status !== 'promoted' && (
                    <IconButton size="small" aria-label={t('common.actions.edit')} onClick={() => setDialog({ open: true, brand: b })}>
                      <EditIcon fontSize="small" />
                    </IconButton>
                  )}
                  {can.delete && (
                    <IconButton size="small" aria-label={t('common.actions.delete')} onClick={() => remove(b)}>
                      <DeleteIcon fontSize="small" />
                    </IconButton>
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </TableContainer>
      <BrandDialog open={dialog.open} brand={dialog.brand} forms={forms} routes={routes} onClose={() => setDialog({ open: false, brand: null })} />
    </Box>
  );
}

CustomBrands.layout = (page: ReactNode) => <PanelLayout title="catalog.custom_brands.title">{page}</PanelLayout>;
