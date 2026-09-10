// The pad designer's right-hand column: the doctor's sheet drawn at true proportion.
//
// "True proportion" is meant literally. The sheet is laid out in real CSS millimetres — 148mm × 210mm for A5,
// margins as padding, the preprinted band as a fixed `headerHeightMm` block — and then scaled as a whole with a
// single `transform: scale()` to fit the column. Nothing inside is resized independently, so every ratio on screen
// is the ratio on paper, and the geometry comes from `padGeometry()`, the mirror of the renderer's own PadGeometry.
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Typography from '@mui/material/Typography';
import { padFlag, padGeometry, PX_PER_MM } from '@panel/lib/clinic/padGeometry';
import { formatBn } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import type { PadSectionKey, PadSettings } from '@shared/types/models';

interface Props {
  pad: PadSettings;
  clinicName: string;
  doctorName: string;
  degrees: string | null;
  bmdc: string | null;
  logoUrl: string | null;
  signatureUrl: string | null;
}

export function PadPreview({ pad, clinicName, doctorName, degrees, bmdc, logoUrl, signatureUrl }: Props) {
  const { t } = useTranslation();
  const frame = useRef<HTMLDivElement | null>(null);
  const [scale, setScale] = useState(1);
  const g = padGeometry(pad);
  const locale = getLocale();
  const mmLabel = (value: number): string => formatBn(value, locale);
  const bn = pad.default_language !== 'en';
  const en = pad.default_language !== 'bn';

  // One scale for the whole sheet, recomputed when the column resizes. Capped at 1 so a wide screen shows the pad
  // at 1:1 rather than blowing it up past life size.
  useEffect(() => {
    const el = frame.current;
    if (!el) return undefined;
    const measure = (): void => {
      const width = el.clientWidth;
      if (width > 0) setScale(Math.min(1, width / (g.paperWidthMm * PX_PER_MM)));
    };
    measure();
    // ResizeObserver is the right tool and is present in every browser this panel targets; the window listener is
    // the fallback for environments that lack it (jsdom under Vitest, very old WebViews).
    if (typeof ResizeObserver === 'undefined') {
      window.addEventListener('resize', measure);
      return () => window.removeEventListener('resize', measure);
    }
    const observer = new ResizeObserver(measure);
    observer.observe(el);
    return () => observer.disconnect();
  }, [g.paperWidthMm]);

  const sheetPx = { width: g.paperWidthMm * PX_PER_MM, height: g.paperHeightMm * PX_PER_MM };
  const mm = (value: number): string => `${value}mm`;

  const heading = (key: string) => (
    <Box sx={{ fontWeight: 700, fontSize: '.82em', letterSpacing: '.04em', textTransform: 'uppercase', color: 'grey.700', mb: '.8mm' }}>
      {t(`clinic.pad.section.${key}`)}
    </Box>
  );

  const sectionBlocks: Record<PadSectionKey, React.ReactNode> = {
    vitals: pad.show_vitals ? (
      <Box key="vitals" sx={{ mt: '2.5mm' }}>
        {heading('vitals')}
        <Box sx={{ display: 'flex', gap: '4mm', flexWrap: 'wrap', fontSize: '.86em' }}>
          <span>BP 120/80 mmHg</span><span>Pulse 78/min</span><span>Temp 100.8°F</span><span>Wt 62 kg</span>
        </Box>
      </Box>
    ) : null,
    complaints: (
      <Box key="complaints" sx={{ mt: '2.5mm' }}>
        {heading('complaints')}
        <Box component="ul" sx={{ m: 0, pl: '5mm' }}><li>{t('clinic.pad.sample.complaint')} — 3 d</li></Box>
      </Box>
    ),
    examination: (
      <Box key="examination" sx={{ mt: '2.5mm' }}>
        {heading('examination')}
        <div>{t('clinic.pad.sample.examination')}</div>
      </Box>
    ),
    diagnosis: (
      <Box key="diagnosis" sx={{ mt: '2.5mm' }}>
        {heading('diagnosis')}
        <Box component="ul" sx={{ m: 0, pl: '5mm' }}>
          <li>{t('clinic.pad.sample.diagnosis')}{padFlag(pad, 'icd_codes') ? <Box component="span" sx={{ color: 'grey.600' }}> (J06.9)</Box> : null}</li>
        </Box>
      </Box>
    ),
    rx: (
      <Box key="rx" sx={{ mt: '2.5mm', display: 'flex', gap: '2mm', alignItems: 'flex-start' }}>
        <Box sx={{ fontSize: '2.4em', fontWeight: 700, lineHeight: .9 }} aria-hidden>℞</Box>
        <Box sx={{ flex: '1 1 auto', fontSize: `${g.rxFontSizePt}pt` }}>
          {[
            { brand: 'Napa 500 mg', generic: 'Paracetamol', bnLine: '১+১+১ · ৫ দিন', enLine: '1+1+1 · 5 days', qty: '15' },
            { brand: 'Fexo 120 mg', generic: 'Fexofenadine', bnLine: '০+০+১ · ৭ দিন', enLine: '0+0+1 · 7 days', qty: '7' },
          ].map((item, index) => (
            <Box key={item.brand} sx={{ display: 'flex', gap: '2mm', py: '1.1mm', borderBottom: index === 0 ? '.4pt dotted' : 0, borderColor: 'grey.400' }}>
              <Box sx={{ width: '8mm', textAlign: 'right', color: 'grey.600' }}>{index + 1}.</Box>
              <Box sx={{ flex: '1 1 auto' }}>
                <Box sx={{ fontWeight: 700 }}>{item.brand}</Box>
                {padFlag(pad, 'generic_names') ? <Box sx={{ color: 'grey.700', fontSize: '.86em' }}>{item.generic}</Box> : null}
                {bn ? <Box>{item.bnLine}</Box> : null}
                {en ? <Box sx={bn ? { fontSize: '.78em', color: 'grey.600' } : undefined}>{item.enLine}</Box> : null}
                {pad.show_drug_info_url ? <Box sx={{ fontSize: '.78em', color: 'grey.600' }}>{t('clinic.pad.preview.more_info')}</Box> : null}
              </Box>
              <Box sx={{ width: '20mm', textAlign: 'right', fontWeight: 600 }}>{item.qty}</Box>
            </Box>
          ))}
        </Box>
      </Box>
    ),
    investigations: (
      <Box key="investigations" sx={{ mt: '2.5mm' }}>
        {heading('investigations')}
        <Box sx={{ display: 'flex', justifyContent: 'space-between' }}>
          <span>1. CBC with ESR</span>
          {padFlag(pad, 'investigation_prices') ? <span>৳450.00</span> : null}
        </Box>
      </Box>
    ),
    advice: (
      <Box key="advice" sx={{ mt: '2.5mm' }}>
        {heading('advice')}
        <Box component="ul" sx={{ m: 0, pl: '5mm' }}><li>{t('clinic.pad.sample.advice')}</li></Box>
      </Box>
    ),
    followup: (
      <Box key="followup" sx={{ mt: '2.5mm' }}>
        {heading('followup')}
        <div>{t('clinic.pad.preview.follow_up')}</div>
      </Box>
    ),
    referral: null,
    signature: null,
  };

  return (
    <Box ref={frame} sx={{ width: '100%' }}>
      <Box sx={{ height: sheetPx.height * scale, width: sheetPx.width * scale, mx: 'auto', overflow: 'hidden' }}>
        <Box
          data-testid="pad-preview-sheet"
          // The geometry in the DOM, the way the print sheet carries `data-preprinted-header`: it makes the
          // preview inspectable, and it is what the component test asserts against instead of emotion classes.
          data-paper={pad.paper_size}
          data-orientation={pad.orientation}
          data-width-mm={g.paperWidthMm}
          data-height-mm={g.paperHeightMm}
          data-margins-mm={`${g.margins.top} ${g.margins.right} ${g.margins.bottom} ${g.margins.left}`}
          data-font-size-pt={g.fontSizePt}
          data-columns={g.columns}
          aria-label={t('clinic.pad.preview.aria')}
          sx={{
            width: sheetPx.width,
            height: sheetPx.height,
            transform: `scale(${scale})`,
            transformOrigin: 'top left',
            bgcolor: 'common.white',
            color: 'common.black',
            boxShadow: 3,
            boxSizing: 'border-box',
            paddingTop: mm(g.margins.top),
            paddingRight: mm(g.margins.right),
            paddingBottom: mm(g.margins.bottom),
            paddingLeft: mm(g.margins.left),
            fontFamily: `'${g.fontFamily}', 'Noto Sans Bengali', 'Inter', system-ui, sans-serif`,
            fontSize: `${g.fontSizePt}pt`,
            lineHeight: 1.45,
            display: 'flex',
            flexDirection: 'column',
          }}
        >
          <Box
            sx={{
              flex: '1 1 auto',
              minHeight: 0,
              overflow: 'hidden',
              // A prescription longer than one sheet flows onto page 2 on paper; here it is simply cut, so fade the
              // last few millimetres rather than slicing a line in half and looking like a rendering fault.
              maskImage: 'linear-gradient(to bottom, #000 calc(100% - 6mm), transparent 100%)',
              WebkitMaskImage: 'linear-gradient(to bottom, #000 calc(100% - 6mm), transparent 100%)',
            }}
          >
            {/* State 1 — preprinted: the doctor's own letterhead is already on the paper. Reserve exactly
                header_height_mm and draw nothing in it. The dashed outline is the preview's only addition and it
                is what a doctor lines the physical pad up against. */}
            {g.preprinted ? (
              <Box
                data-testid="pad-preview-blank-band"
                data-band-mm={g.headerHeightMm}
                sx={{
                  height: mm(g.headerHeightMm),
                  border: '1px dashed',
                  borderColor: 'primary.light',
                  display: 'flex', alignItems: 'center', justifyContent: 'center',
                  color: 'primary.main', fontSize: '7pt', textAlign: 'center', px: 1,
                }}
              >
                {t('clinic.pad.preview.blank_band', { mm: mmLabel(g.headerHeightMm) })}
              </Box>
            ) : g.letterhead ? (
              <Box sx={{ display: 'flex', justifyContent: 'space-between', gap: '4mm', pb: '2mm' }}>
                <Box>
                  {logoUrl ? <Box component="img" src={logoUrl} alt="" sx={{ maxHeight: '18mm', maxWidth: '34mm', objectFit: 'contain', display: 'block', mb: '1mm' }} /> : null}
                  <Box sx={{ fontSize: '1.35em', fontWeight: 700, lineHeight: 1.2 }}>{clinicName}</Box>
                  <Box sx={{ fontSize: '.86em', color: 'grey.600' }}>{t('clinic.pad.sample.branch')} · {t('clinic.pad.sample.address')}</Box>
                </Box>
                <Box sx={{ textAlign: 'right' }}>
                  <Box sx={{ fontSize: '1.15em', fontWeight: 700, lineHeight: 1.2 }}>{doctorName}</Box>
                  {degrees ? <Box sx={{ fontSize: '.86em' }}>{degrees}</Box> : null}
                  {bmdc ? <Box sx={{ fontSize: '.86em', color: 'grey.600' }}>BMDC {bmdc}</Box> : null}
                </Box>
              </Box>
            ) : null}
            <Box sx={{ borderTop: g.preprinted ? 0 : '1.2pt solid', borderColor: 'common.black', mt: g.preprinted ? '2mm' : '1.5mm', mb: '2mm' }} />

            <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: '1mm 5mm', py: '1.5mm' }}>
              <b>{t('clinic.pad.sample.patient')}</b>
              <Box component="span" sx={{ color: 'grey.600' }}>32 y · M</Box>
              <Box component="span" sx={{ color: 'grey.600' }}>P-000000</Box>
              <Box component="span" sx={{ color: 'grey.600' }}>A-001</Box>
            </Box>
            <Box sx={{ borderTop: '.6pt solid', borderColor: 'grey.400', mb: '1mm' }} />

            {g.columns === 2 ? (
              <Box sx={{ display: 'grid', gridTemplateColumns: '33% 1fr', gap: '0 5mm', alignItems: 'start' }}>
                <Box sx={{ borderRight: '.6pt solid', borderColor: 'grey.400', pr: '4mm' }}>
                  {g.sections.filter((s) => ['vitals', 'complaints', 'examination', 'diagnosis'].includes(s)).map((s) => sectionBlocks[s])}
                </Box>
                <Box>
                  {g.sections.filter((s) => ['rx', 'investigations', 'advice', 'followup', 'referral'].includes(s)).map((s) => sectionBlocks[s])}
                </Box>
              </Box>
            ) : (
              g.sections.filter((s) => s !== 'signature').map((s) => sectionBlocks[s])
            )}
          </Box>

          <Box sx={{ flex: '0 0 auto', pb: g.reservedFooterMm > 0 ? mm(g.reservedFooterMm) : 0 }}>
            <Box sx={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', gap: '3mm', mt: '4mm', pt: '2mm' }}>
              <Box sx={{ flex: '1 1 40mm', minWidth: 0, fontSize: '.78em', color: 'grey.600' }}>
                {pad.footer_html && !g.preprinted ? <Box sx={{ mb: '.5mm' }}>{stripTags(pad.footer_html)}</Box> : null}
                <div>{t('clinic.pad.preview.verification')}</div>
              </Box>
              <Box sx={{ textAlign: 'center', minWidth: '40mm' }}>
                {signatureUrl
                  ? <Box component="img" src={signatureUrl} alt="" sx={{ maxHeight: '14mm', maxWidth: '46mm', objectFit: 'contain', display: 'block', mx: 'auto', mb: '.5mm' }} />
                  : <Box sx={{ height: '12mm' }} />}
                <Box sx={{ borderTop: '.8pt solid', borderColor: 'common.black', pt: '.8mm', fontWeight: 600 }}>{doctorName}</Box>
              </Box>
              {pad.show_qr ? <Box sx={{ width: '20mm', height: '20mm', border: '.6pt solid', borderColor: 'grey.500', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: '6pt', color: 'grey.600' }}>QR</Box> : null}
            </Box>
            {g.reservedFooterMm > 0 ? (
              <Box
                data-testid="pad-preview-footer-band"
                data-band-mm={g.reservedFooterMm}
                sx={{ height: mm(g.reservedFooterMm), mt: '1mm', border: '1px dashed', borderColor: 'primary.light', display: 'flex', alignItems: 'center', justifyContent: 'center', color: 'primary.main', fontSize: '7pt' }}
              >
                {t('clinic.pad.preview.footer_band', { mm: mmLabel(g.reservedFooterMm) })}
              </Box>
            ) : null}
          </Box>
        </Box>
      </Box>

      <Typography variant="caption" color="text.secondary" sx={{ display: 'block', textAlign: 'center', mt: 1 }}>
        {t('clinic.pad.preview.scale', {
          paper: pad.paper_size,
          width: mmLabel(g.paperWidthMm),
          height: mmLabel(g.paperHeightMm),
          percent: mmLabel(Math.round(scale * 100)),
        })}
      </Typography>
    </Box>
  );
}

/** The footer HTML is sanitised server-side; the preview shows its text only, never executes it. */
function stripTags(html: string): string {
  return html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
}
