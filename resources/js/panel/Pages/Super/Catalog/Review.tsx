// The custom-brand promotion queue (CATALOG.md §8): a doctor typed a brand the master catalogue does not have,
// and a human decides whether it becomes a master brand or maps onto one that already exists.
//
// The Catalog module owns these endpoints and they are JSON, not Inertia — the server hands this page their URLs
// in `endpoints`, with the literal `__ID__` where a promotion's public_id goes. Everything else in the console is
// an Inertia form post; this screen is the one exception, so all of its calls live in panel/api/super.ts.
//
// What an operator sees for one brand: the clinic's proposal (name, molecule, strength, form, route, use count)
// side by side with the master brands whose names resemble it — from the same Meilisearch index doctors type
// into — each with its presentations, so "map" is a comparison, not a guess.
import { useCallback, useEffect, useState, type FormEvent, type ReactNode, type SyntheticEvent } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import CircularProgress from '@mui/material/CircularProgress';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import Divider from '@mui/material/Divider';
import InputAdornment from '@mui/material/InputAdornment';
import List from '@mui/material/List';
import ListItemButton from '@mui/material/ListItemButton';
import ListItemText from '@mui/material/ListItemText';
import MenuItem from '@mui/material/MenuItem';
import Radio from '@mui/material/Radio';
import Stack from '@mui/material/Stack';
import Tab from '@mui/material/Tab';
import Tabs from '@mui/material/Tabs';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import ChevronLeftIcon from '@mui/icons-material/ChevronLeft';
import ChevronRightIcon from '@mui/icons-material/ChevronRight';
import SearchIcon from '@mui/icons-material/Search';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { approvePromotion, listPromotionTenants, listPromotions, rejectPromotion, showPromotion } from '@panel/api/super';
import type { JsonPage, PromotionDetail, PromotionRow, PromotionTenantOption } from '@panel/Components/Super/types';
import { isApiError } from '@shared/http';
import { formatNumber } from '@shared/format/number';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import { hasRoute, route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

interface Endpoints {
  index: string;
  show: string;
  approve: string;
  reject: string;
  tenants?: string;
}

type Props = PageProps<{
  status: string;
  statuses: string[];
  counts: Record<string, number>;
  endpoints: Endpoints;
}>;

const SEARCH_DEBOUNCE_MS = 300;

function message(error: unknown, fallback: string): string {
  return isApiError(error) && error.message !== '' ? error.message : fallback;
}

function Field({ label, value }: { label: string; value: string | number | null | undefined }) {
  return (
    <Box>
      <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{label}</Typography>
      <Typography variant="body2" lang="bn">{value === null || value === undefined || value === '' ? '—' : String(value)}</Typography>
    </Box>
  );
}

export default function Review({ status, statuses, counts, endpoints }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();

  const [page, setPage] = useState(1);
  const [q, setQ] = useState('');
  const [tenant, setTenant] = useState('');
  const [tenants, setTenants] = useState<PromotionTenantOption[]>([]);
  const [list, setList] = useState<JsonPage<PromotionRow> | null>(null);
  const [listError, setListError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  const [selected, setSelected] = useState<string | null>(null);
  const [detail, setDetail] = useState<PromotionDetail | null>(null);
  const [detailError, setDetailError] = useState<string | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);

  const [brandId, setBrandId] = useState<number | null>(null);
  const [note, setNote] = useState('');
  const [presentations, setPresentations] = useState('');
  const [rejectOpen, setRejectOpen] = useState(false);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [lastNotice, setLastNotice] = useState<string | null>(null);

  const load = useCallback((signal?: AbortSignal) => {
    setLoading(true);
    setListError(null);
    return listPromotions(endpoints.index, { status, page, ...(q.trim() === '' ? {} : { q: q.trim() }), ...(tenant === '' ? {} : { tenant }) }, signal)
      .then((data) => setList(data))
      .catch((error: unknown) => { if (!signal?.aborted) setListError(message(error, t('super.review.load_failed'))); })
      .finally(() => setLoading(false));
  }, [endpoints.index, status, page, q, tenant, t]);

  useEffect(() => {
    const controller = new AbortController();
    const timer = window.setTimeout(() => { void load(controller.signal); }, SEARCH_DEBOUNCE_MS);
    return () => { window.clearTimeout(timer); controller.abort(); };
  }, [load]);

  useEffect(() => {
    if (!endpoints.tenants) return;
    const controller = new AbortController();
    listPromotionTenants(endpoints.tenants, controller.signal).then(setTenants).catch(() => { /* the filter is a convenience */ });
    return () => controller.abort();
  }, [endpoints.tenants]);

  useEffect(() => {
    if (selected === null) { setDetail(null); return; }
    const controller = new AbortController();
    setDetailLoading(true);
    setDetailError(null);
    setActionError(null);
    setLastNotice(null);
    showPromotion(endpoints.show, selected, controller.signal)
      .then((data) => {
        setDetail(data);
        setBrandId(data.similar_master_brands.find((brand) => brand.same_generic)?.id ?? null);
        const snap = data.proposed;
        setPresentations(snap?.strength ? `${snap.form ?? 'tab'}:${snap.strength}` : '');
      })
      .catch((error: unknown) => { if (!controller.signal.aborted) setDetailError(message(error, t('super.review.load_failed'))); })
      .finally(() => setDetailLoading(false));
    return () => controller.abort();
  }, [selected, endpoints.show, t]);

  const changeStatus = (_event: SyntheticEvent, next: string): void => {
    setSelected(null);
    setPage(1);
    router.get(route('super.catalog.review'), { status: next }, { preserveState: false, replace: true });
  };

  const afterDecision = (notice: string | null): void => {
    setSelected(null);
    setDetail(null);
    setNote('');
    setReason('');
    setLastNotice(notice);
    void load();
    router.reload({ only: ['counts'] });
  };

  const approve = (mode: 'map' | 'create'): void => {
    if (detail === null) return;
    setBusy(true);
    setActionError(null);
    approvePromotion(endpoints.approve, detail.public_id, {
      mode,
      ...(mode === 'map' ? { brand_id: brandId } : {}),
      ...(mode === 'create' && presentations.trim() !== '' ? { presentations: presentations.trim() } : {}),
      ...(note.trim() === '' ? {} : { note: note.trim() }),
    })
      .then(() => afterDecision(t(mode === 'map' ? 'super.review.notice.mapped' : 'super.review.notice.created', { name: detail.brand_name })))
      .catch((error: unknown) => setActionError(message(error, t('super.review.action_failed'))))
      .finally(() => setBusy(false));
  };

  const reject = (event: FormEvent): void => {
    event.preventDefault();
    if (detail === null) return;
    setBusy(true);
    setActionError(null);
    rejectPromotion(endpoints.reject, detail.public_id, { reason: reason.trim() })
      .then((result) => { setRejectOpen(false); afterDecision(t(result.notified ? 'super.review.notice.rejected_notified' : 'super.review.notice.rejected', { name: detail.brand_name })); })
      .catch((error: unknown) => setActionError(message(error, t('super.review.action_failed'))))
      .finally(() => setBusy(false));
  };

  const rows = list?.data ?? [];
  const chosen = detail?.similar_master_brands.find((b) => b.id === brandId) ?? null;

  return (
    <Box>
      <Stack spacing={2}>
        <Box sx={{ borderBottom: 1, borderColor: 'divider' }}>
          <Tabs value={status} onChange={changeStatus} variant="scrollable" scrollButtons="auto" allowScrollButtonsMobile>
            {statuses.map((value) => (
              <Tab
                key={value}
                value={value}
                sx={{ textTransform: 'none' }}
                label={`${t(`super.review.status.${value}`, { defaultValue: value })} · ${formatNumber(counts[value] ?? 0, locale)}`}
              />
            ))}
          </Tabs>
        </Box>

        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1}>
          <TextField
            size="small"
            value={q}
            onChange={(e) => { setQ(e.target.value); setPage(1); }}
            placeholder={t('super.review.search_placeholder')}
            slotProps={{ input: { startAdornment: <InputAdornment position="start"><SearchIcon fontSize="small" /></InputAdornment>, 'aria-label': t('super.review.search_placeholder') } }}
            sx={{ minWidth: 260 }}
          />
          {tenants.length > 0 ? (
            <TextField select size="small" value={tenant} onChange={(e) => { setTenant(e.target.value); setPage(1); }} sx={{ minWidth: 220 }} slotProps={{ htmlInput: { 'aria-label': t('super.review.filter.tenant') } }}>
              <MenuItem value="">{t('super.review.filter.all_tenants')}</MenuItem>
              {tenants.map((option) => <MenuItem key={option.public_id} value={option.public_id}><span lang="bn">{option.name}</span></MenuItem>)}
            </TextField>
          ) : null}
        </Stack>

        {listError ? <Alert severity="error">{listError}</Alert> : null}
        {lastNotice ? <Alert severity="success" onClose={() => setLastNotice(null)}>{lastNotice}</Alert> : null}

        <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', md: '360px 1fr' }, alignItems: 'start' }}>
          <Card variant="outlined">
            <CardContent sx={{ py: 1.5, '&:last-child': { pb: 0 } }}>
              <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
                <Typography variant="subtitle2" sx={{ flexGrow: 1 }}>{t('super.review.queue')}</Typography>
                {loading ? <CircularProgress size={16} /> : null}
              </Stack>
            </CardContent>
            <List dense disablePadding>
              {rows.length === 0 && !loading ? (
                <Box sx={{ p: 3 }}>
                  <Typography variant="body2" color="text.secondary" sx={{ textAlign: 'center' }}>{t('super.review.empty')}</Typography>
                </Box>
              ) : rows.map((row) => (
                <ListItemButton key={row.public_id} selected={selected === row.public_id} onClick={() => setSelected(row.public_id)}>
                  <ListItemText
                    primary={row.brand_name}
                    secondary={`${row.generic_name ?? t('super.review.no_generic')} · ${row.tenant.name ?? row.tenant.slug ?? ''} · ${row.submitted_at === null ? '—' : formatDhaka(row.submitted_at, 'D MMM YYYY', locale)}`}
                    slotProps={{ primary: { variant: 'body2', sx: { fontWeight: 600 } }, secondary: { variant: 'caption', lang: 'bn' } }}
                  />
                </ListItemButton>
              ))}
            </List>
            {list !== null && list.last_page > 1 ? (
              <Stack direction="row" spacing={1} sx={{ alignItems: 'center', justifyContent: 'flex-end', p: 1 }}>
                <Typography variant="caption" color="text.secondary">
                  {t('super.tenants.page_of', {
                    current: formatNumber(list.current_page, locale),
                    last: formatNumber(list.last_page, locale),
                    total: formatNumber(list.total, locale),
                  })}
                </Typography>
                <Button size="small" startIcon={<ChevronLeftIcon />} disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('super.actions.prev')}</Button>
                <Button size="small" endIcon={<ChevronRightIcon />} disabled={page >= list.last_page} onClick={() => setPage(page + 1)}>{t('super.actions.next')}</Button>
              </Stack>
            ) : null}
          </Card>

          <Card variant="outlined">
            <CardContent>
              {detailLoading ? <CircularProgress size={20} /> : null}
              {detailError ? <Alert severity="error">{detailError}</Alert> : null}
              {detail === null && !detailLoading && detailError === null ? (
                <Typography variant="body2" color="text.secondary">{t('super.review.pick_one')}</Typography>
              ) : null}

              {detail !== null ? (
                <Stack spacing={2}>
                  <Box>
                    <Stack direction="row" spacing={1} useFlexGap sx={{ alignItems: 'center', flexWrap: 'wrap' }}>
                      <Typography variant="h6" component="h2">{detail.brand_name}</Typography>
                      <Chip size="small" variant="outlined" label={t(`super.review.status.${detail.status}`, { defaultValue: detail.status })} />
                      <Chip size="small" variant="outlined" label={t('super.review.use_count', { count: formatNumber(detail.use_count, locale) })} />
                      {detail.catalog_generic && !detail.catalog_generic.is_active ? <Chip size="small" color="error" label={t('super.review.generic_inactive')} /> : null}
                      {detail.catalog_generic === null ? <Chip size="small" color="error" label={t('super.review.generic_missing')} /> : null}
                    </Stack>
                    <Typography variant="body2" color="text.secondary">
                      {t('super.review.submitted_by', {
                        tenant: detail.tenant.name ?? detail.tenant.slug ?? '',
                        date: detail.submitted_at === null ? '—' : formatDhaka(detail.submitted_at, 'D MMM YYYY, h:mm a', locale),
                      })}
                      {detail.tenant.public_id && hasRoute('super.tenants.show') ? (
                        <> · <Box component={RouterLink} href={route('super.tenants.show', { tenant: detail.tenant.public_id })} sx={{ color: 'primary.main' }}>{t('super.review.open_tenant')}</Box></>
                      ) : null}
                    </Typography>
                  </Box>

                  <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', md: '1fr 1fr' } }}>
                    <Card variant="outlined" sx={{ bgcolor: 'action.hover' }}>
                      <CardContent>
                        <Typography variant="subtitle2" gutterBottom>{t('super.review.proposed')}</Typography>
                        <Stack spacing={1}>
                          <Field label={t('super.review.brand')} value={detail.proposed?.brand_name ?? detail.brand_name} />
                          <Field label={t('super.review.generic')} value={detail.proposed?.generic_name ?? detail.generic_name ?? t('super.review.no_generic')} />
                          <Field label={t('super.review.manufacturer')} value={detail.proposed?.manufacturer ?? detail.manufacturer} />
                          <Field label={t('super.review.strength')} value={detail.proposed?.strength} />
                          <Field label={t('super.review.form')} value={detail.proposed?.form} />
                          <Field label={t('super.review.route')} value={detail.proposed?.route} />
                        </Stack>
                      </CardContent>
                    </Card>
                    <Card variant="outlined">
                      <CardContent>
                        <Typography variant="subtitle2" gutterBottom>{t('super.review.master_match')}</Typography>
                        {chosen === null ? (
                          <Typography variant="body2" color="text.secondary">{t('super.review.pick_master')}</Typography>
                        ) : (
                          <Stack spacing={1}>
                            <Field label={t('super.review.brand')} value={chosen.name} />
                            <Field label={t('super.review.generic')} value={chosen.generic_name} />
                            <Field label={t('super.review.manufacturer')} value={chosen.manufacturer} />
                            <Box>
                              <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{t('super.review.presentations')}</Typography>
                              {(chosen.strengths ?? []).length === 0 ? <Typography variant="body2">—</Typography> : (chosen.strengths ?? []).map((s) => (
                                <Typography key={s.id} variant="body2" color={s.is_active ? 'text.primary' : 'text.disabled'}>{s.strength_label} · {s.form ?? '—'}{s.pack_size ? ` · ${s.pack_size}` : ''}</Typography>
                              ))}
                            </Box>
                          </Stack>
                        )}
                      </CardContent>
                    </Card>
                  </Box>

                  <Divider />

                  <Box>
                    <Typography variant="subtitle2" gutterBottom>{t('super.review.similar')}</Typography>
                    {detail.similar_master_brands.length === 0 ? (
                      <Typography variant="body2" color="text.secondary">{t('super.review.no_similar')}</Typography>
                    ) : (
                      <Stack divider={<Divider flexItem />}>
                        {detail.similar_master_brands.map((brand) => (
                          <Stack key={brand.id} direction="row" spacing={1} sx={{ alignItems: 'center', py: 0.5 }}>
                            <Radio
                              size="small"
                              checked={brandId === brand.id}
                              onChange={() => setBrandId(brand.id)}
                              slotProps={{ input: { 'aria-label': brand.name } }}
                            />
                            <Box sx={{ flexGrow: 1, minWidth: 0 }}>
                              <Typography variant="body2" sx={{ fontWeight: 600 }}>{brand.name}</Typography>
                              <Typography variant="caption" color="text.secondary">
                                {brand.generic_name ?? '—'}{brand.manufacturer === null ? '' : ` · ${brand.manufacturer}`}{brand.strengths && brand.strengths.length > 0 ? ` · ${brand.strengths.map((s) => s.strength_label).join(', ')}` : ''}
                              </Typography>
                            </Box>
                            <Chip
                              size="small"
                              color={brand.same_generic ? 'success' : 'warning'}
                              variant="outlined"
                              label={brand.same_generic ? t('super.review.same_generic') : t('super.review.different_generic')}
                            />
                            {brand.is_active ? null : <Chip size="small" variant="outlined" label={t('super.review.inactive_brand')} />}
                          </Stack>
                        ))}
                      </Stack>
                    )}
                  </Box>

                  {detail.status === 'pending' ? (
                    <>
                      <TextField
                        size="small"
                        label={t('super.review.presentations_field')}
                        value={presentations}
                        onChange={(e) => setPresentations(e.target.value)}
                        helperText={t('super.review.presentations_help')}
                        slotProps={{ htmlInput: { maxLength: 1000 } }}
                      />
                      <TextField
                        size="small"
                        label={t('super.review.note')}
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                        helperText={t('super.review.note_help')}
                        slotProps={{ htmlInput: { maxLength: 255 } }}
                      />

                      {actionError ? <Alert severity="error">{actionError}</Alert> : null}

                      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1}>
                        <Button variant="contained" disabled={busy || brandId === null || chosen?.same_generic === false} onClick={() => approve('map')}>
                          {t('super.review.approve_map')}
                        </Button>
                        <Button variant="outlined" disabled={busy} onClick={() => approve('create')}>
                          {t('super.review.approve_create')}
                        </Button>
                        <Button color="error" disabled={busy} onClick={() => { setReason(''); setRejectOpen(true); }}>
                          {t('super.review.reject')}
                        </Button>
                      </Stack>
                    </>
                  ) : (
                    <Alert severity="info">
                      {t('super.review.decided', { status: t(`super.review.status.${detail.status}`, { defaultValue: detail.status }), date: detail.reviewed_at ? formatDhaka(detail.reviewed_at, 'D MMM YYYY, h:mm a', locale) : '—' })}
                      {detail.decision && typeof detail.decision.reason === 'string' && detail.decision.reason !== '' ? ` — ${detail.decision.reason}` : ''}
                    </Alert>
                  )}
                </Stack>
              ) : null}
            </CardContent>
          </Card>
        </Box>
      </Stack>

      <Dialog open={rejectOpen} onClose={() => setRejectOpen(false)} fullWidth maxWidth="sm">
        <Box component="form" onSubmit={reject} noValidate>
          <DialogTitle>{t('super.review.reject_title')}</DialogTitle>
          <DialogContent sx={{ display: 'grid', gap: 2, pt: 1 }}>
            <Typography variant="body2" color="text.secondary">{t('super.review.reject_body', { name: detail?.brand_name ?? '' })}</Typography>
            <TextField
              label={t('super.tenants.reason')}
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              required autoFocus multiline minRows={2}
              helperText={t('super.review.reject_help')}
              slotProps={{ htmlInput: { maxLength: 255, minLength: 3 } }}
            />
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setRejectOpen(false)}>{t('super.actions.cancel')}</Button>
            <Button type="submit" color="error" variant="contained" disabled={busy || reason.trim().length < 3}>
              {t('super.review.reject')}
            </Button>
          </DialogActions>
        </Box>
      </Dialog>
    </Box>
  );
}

Review.layout = (page: ReactNode) => <PanelLayout title="super.review.title">{page}</PanelLayout>;
