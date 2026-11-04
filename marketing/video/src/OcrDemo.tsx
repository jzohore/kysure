import {
  AbsoluteFill,
  interpolate,
  Sequence,
  spring,
  staticFile,
  useCurrentFrame,
  useVideoConfig,
} from 'remotion';
import { C, Caption, Card, Check, ease, Frame, useInter, useReveal, Warn } from './ui';

/** Durées des scènes en images (30 fps). */
const D = { hook: 120, upload: 210, ocr: 270, findings: 240, decision: 240, outro: 180 };
const START = {
  hook: 0,
  upload: D.hook,
  ocr: D.hook + D.upload,
  findings: D.hook + D.upload + D.ocr,
  decision: D.hook + D.upload + D.ocr + D.findings,
  outro: D.hook + D.upload + D.ocr + D.findings + D.decision,
};
export const OCR_DEMO_DURATION = START.outro + D.outro;

const clamp = { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' } as const;

/** Fondu de sortie commun : on coupe proprement entre deux scènes. */
const SceneFade = ({ duration, children }: { duration: number; children: React.ReactNode }) => {
  const frame = useCurrentFrame();
  const opacity = interpolate(frame, [duration - 8, duration], [1, 0], clamp);
  return <AbsoluteFill style={{ opacity }}>{children}</AbsoluteFill>;
};

const Title = ({ children, delay = 0 }: { children: React.ReactNode; delay?: number }) => (
  <div style={{ fontSize: 60, fontWeight: 700, letterSpacing: -1.5, lineHeight: 1.1, ...useReveal(delay) }}>
    {children}
  </div>
);

/* ─────────────── Scène 1 — accroche ─────────────── */
const Hook = () => {
  const l1 = useReveal(6);
  const l2 = useReveal(44);
  return (
    <AbsoluteFill style={{ padding: '0 80px', justifyContent: 'center', gap: 36 }}>
      <div style={{ fontSize: 78, fontWeight: 700, letterSpacing: -2.5, lineHeight: 1.08, ...l1 }}>
        Un client dépose
        <br />
        sa carte d’identité.
      </div>
      <div
        style={{
          fontSize: 78,
          fontWeight: 700,
          letterSpacing: -2.5,
          lineHeight: 1.08,
          color: C.text2,
          ...l2,
        }}
      >
        Vous la relisez,
        <br />
        champ par champ ?
      </div>
    </AbsoluteFill>
  );
};

/* ─────────────── Scène 2 — dépôt client ─────────────── */
const UploadRow = ({
  label,
  file,
  start,
  pending,
}: {
  label: string;
  file: string;
  start: number;
  pending?: boolean;
}) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const slide = spring({ frame: frame - start, fps, config: { damping: 18, stiffness: 120 } });
  const progress = interpolate(frame, [start + 14, start + 70], [0, 100], { ...clamp, easing: ease });
  const done = progress >= 100;
  return (
    <div style={{ padding: '28px 32px', borderTop: `2px solid ${C.border}` }}>
      <div style={{ fontSize: 32, fontWeight: 600 }}>{label}</div>
      {pending ? (
        <div
          style={{
            marginTop: 16,
            border: `3px dashed ${C.border}`,
            borderRadius: 18,
            padding: '26px 0',
            textAlign: 'center',
            fontSize: 28,
            color: C.text2,
          }}
        >
          Déposer un fichier
        </div>
      ) : (
        <div
          style={{
            marginTop: 16,
            border: `2px solid ${done ? C.emerald : C.blue}`,
            borderRadius: 18,
            padding: '20px 24px',
            background: C.slate50,
            transform: `translateY(${(1 - slide) * 80}px)`,
            opacity: slide,
          }}
        >
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span style={{ fontSize: 28, fontWeight: 500 }}>{file}</span>
            <span style={{ fontSize: 26, fontWeight: 600, color: done ? C.emerald : C.blue }}>
              {done ? 'Reçue' : `${Math.round(progress)} %`}
            </span>
          </div>
          <div style={{ height: 10, borderRadius: 99, background: C.border, marginTop: 14 }}>
            <div
              style={{
                width: `${progress}%`,
                height: '100%',
                borderRadius: 99,
                background: done ? C.emerald : C.blue,
              }}
            />
          </div>
        </div>
      )}
    </div>
  );
};

const Upload = () => (
  <AbsoluteFill style={{ padding: '190px 60px 0' }}>
    <Title>Le client dépose sa pièce.</Title>
    <Card style={{ marginTop: 44 }}>
      <div style={{ padding: '28px 32px', fontSize: 28, color: C.text2, display: 'flex', gap: 12 }}>
        <span aria-hidden>🔒</span> Espace client sécurisé
      </div>
      <UploadRow label="Carte d’identité — recto" file="cni_martin_recto.jpg" start={24} />
      <UploadRow label="Justificatif de domicile" file="" start={0} pending />
    </Card>
    <Caption text="Le client dépose sa pièce depuis son espace sécurisé" duration={D.upload} />
  </AbsoluteFill>
);

/* ─────────────── Scène 3 — lecture OCR ─────────────── */
const FIELDS: [string, string][] = [
  ['Nom', 'MARTIN'],
  ['Prénom', 'Claire'],
  ['Date de naissance', '12/03/1984'],
  ['N° de pièce', '24AB•••••'],
  ['Valable jusqu’au', '09/2027'],
];

const IdCard = () => {
  const frame = useCurrentFrame();
  const scanY = interpolate(frame, [20, 110], [0, 100], { ...clamp, easing: ease });
  const scanning = frame > 20 && frame < 120;
  const bar = (w: number, o = 0.14) => (
    <div style={{ height: 18, width: w, borderRadius: 9, background: C.ink, opacity: o }} />
  );
  return (
    <Card style={{ height: 380, position: 'relative', overflow: 'hidden', padding: 36 }}>
      <div style={{ fontSize: 22, letterSpacing: 4, color: C.text2, fontWeight: 600 }}>
        RÉPUBLIQUE FRANÇAISE · CARTE NATIONALE D’IDENTITÉ
      </div>
      <div style={{ display: 'flex', gap: 36, marginTop: 36 }}>
        <div style={{ width: 200, height: 230, borderRadius: 16, background: '#cbd5e1' }} />
        <div style={{ display: 'flex', flexDirection: 'column', gap: 22, paddingTop: 12 }}>
          {bar(420, 0.8)}
          {bar(320)}
          {bar(380)}
          {bar(260)}
        </div>
      </div>
      {scanning && (
        <div
          style={{
            position: 'absolute',
            left: 0,
            right: 0,
            top: `${scanY}%`,
            height: 6,
            background: C.blue,
            boxShadow: `0 0 28px 8px rgba(37,99,235,0.35)`,
          }}
        />
      )}
    </Card>
  );
};

const Ocr = () => {
  const frame = useCurrentFrame();
  return (
    <AbsoluteFill style={{ padding: '190px 60px 0' }}>
      <Title>L’OCR la lit en quelques secondes.</Title>
      <div style={{ marginTop: 40 }}>
        <IdCard />
      </div>
      <Card style={{ marginTop: 28 }}>
        {FIELDS.map(([label, value], i) => {
          const at = 70 + i * 22;
          const shown = Math.floor(interpolate(frame, [at, at + 16], [0, value.length], clamp));
          return (
            <div
              key={label}
              style={{
                display: 'flex',
                justifyContent: 'space-between',
                padding: '17px 32px',
                borderTop: i ? `2px solid ${C.border}` : 'none',
                fontSize: 30,
                opacity: frame > at - 4 ? 1 : 0.25,
              }}
            >
              <span style={{ color: C.text2 }}>{label}</span>
              <span style={{ fontWeight: 600, fontVariantNumeric: 'tabular-nums' }}>
                {value.slice(0, shown)}
              </span>
            </div>
          );
        })}
      </Card>
      <Caption text="L’OCR lit la pièce et extrait les données" duration={D.ocr} />
    </AbsoluteFill>
  );
};

/* ─────────────── Scène 4 — points de vigilance ─────────────── */
const FINDINGS = [
  { ok: true, t: 'Pièce en cours de validité', d: 'Valable jusqu’au 09/2027' },
  { ok: true, t: 'Identité identique à la fiche client', d: 'Claire MARTIN' },
  { ok: false, t: 'Justificatif de domicile ancien', d: 'Daté de plus de 3 mois — à vérifier' },
];

const Findings = () => (
  <AbsoluteFill style={{ padding: '190px 60px 0' }}>
    <Title>Elle signale les points de vigilance.</Title>
    <div style={{ marginTop: 48, display: 'flex', flexDirection: 'column', gap: 24 }}>
      {FINDINGS.map((f, i) => (
        <Card
          key={f.t}
          style={{
            padding: '30px 34px',
            borderLeft: `12px solid ${f.ok ? C.emerald : C.amber}`,
            display: 'flex',
            gap: 24,
            alignItems: 'center',
            ...useReveal(24 + i * 22, 40),
          }}
        >
          {f.ok ? <Check size={52} /> : <Warn size={52} />}
          <div>
            <div style={{ fontSize: 36, fontWeight: 650 }}>{f.t}</div>
            <div style={{ fontSize: 28, color: C.text2, marginTop: 6 }}>{f.d}</div>
          </div>
        </Card>
      ))}
    </div>
    <div style={{ marginTop: 36, fontSize: 32, fontWeight: 600, color: C.text2, ...useReveal(120) }}>
      L’OCR n’accepte ni ne rejette : elle prépare.
    </div>
    <Caption text="Identité, validité, ancienneté : chaque écart est signalé" duration={D.findings} />
  </AbsoluteFill>
);

/* ─────────────── Scène 5 — décision du CGP ─────────────── */
const Cursor = ({ x, y, pressed }: { x: number; y: number; pressed: boolean }) => (
  <svg
    width="56"
    height="56"
    viewBox="0 0 24 24"
    style={{
      position: 'absolute',
      left: x,
      top: y,
      transform: `scale(${pressed ? 0.86 : 1})`,
      filter: 'drop-shadow(0 4px 6px rgba(15,23,42,0.35))',
    }}
    aria-hidden
  >
    <path d="M4 2l15 9-6.5 1.6L9.8 19 4 2z" fill="#fff" stroke={C.ink} strokeWidth="1.5" strokeLinejoin="round" />
  </svg>
);

const Decision = () => {
  const frame = useCurrentFrame();
  const CLICK = 105;
  const validated = frame >= CLICK;
  const cursor = {
    x: interpolate(frame, [30, CLICK - 6], [820, 280], { ...clamp, easing: ease }),
    y: interpolate(frame, [30, CLICK - 6], [900, 500], { ...clamp, easing: ease }),
  };
  const pressed = frame >= CLICK - 6 && frame < CLICK + 6;
  const audit = useReveal(CLICK + 16, 30);
  return (
    <AbsoluteFill style={{ padding: '190px 60px 0' }}>
      <Title>Le CGP tranche en un clic.</Title>
      <Card style={{ marginTop: 44, padding: 36 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <div>
            <div style={{ fontSize: 36, fontWeight: 650 }}>Carte d’identité — recto</div>
            <div style={{ fontSize: 28, color: C.text2, marginTop: 6 }}>Claire MARTIN</div>
          </div>
          <div
            style={{
              fontSize: 28,
              fontWeight: 600,
              padding: '10px 22px',
              borderRadius: 999,
              border: `2px solid ${validated ? C.emerald : C.border}`,
              color: validated ? '#047857' : C.text2,
              background: validated ? '#ecfdf5' : C.slate50,
            }}
          >
            {validated ? 'Validée' : 'À examiner'}
          </div>
        </div>
        <div style={{ display: 'flex', gap: 20, marginTop: 40 }}>
          <div
            style={{
              flex: 1,
              textAlign: 'center',
              fontSize: 32,
              fontWeight: 600,
              padding: '26px 0',
              borderRadius: 16,
              background: C.blue,
              color: '#fff',
              transform: pressed ? 'scale(0.97)' : 'none',
            }}
          >
            Valider la pièce
          </div>
          <div
            style={{
              flex: 1,
              textAlign: 'center',
              fontSize: 32,
              fontWeight: 600,
              padding: '26px 0',
              borderRadius: 16,
              border: `2px solid ${C.border}`,
              color: C.ink,
            }}
          >
            Redemander
          </div>
        </div>
      </Card>
      {validated && (
        <Card
          style={{
            marginTop: 28,
            padding: '26px 34px',
            borderLeft: `12px solid ${C.blue}`,
            ...audit,
          }}
        >
          <div style={{ fontSize: 24, fontWeight: 600, color: C.blue, letterSpacing: 1 }}>
            JOURNAL D’AUDIT
          </div>
          <div style={{ fontSize: 30, marginTop: 8 }}>
            Pièce validée par Camille D. · 14:32
          </div>
        </Card>
      )}
      <Cursor x={cursor.x} y={cursor.y} pressed={pressed} />
      <Caption text="Le CGP valide ou redemande — l’action est journalisée" duration={D.decision} />
    </AbsoluteFill>
  );
};

/* ─────────────── Scène 6 — conclusion ─────────────── */
const Outro = () => {
  const l1 = useReveal(8);
  const l2 = useReveal(34);
  const l3 = useReveal(62);
  return (
    <AbsoluteFill style={{ padding: '0 80px', justifyContent: 'center', gap: 20 }}>
      <img
        src={staticFile('kysure_logo.png')}
        style={{ width: 240, height: 130, objectFit: 'contain', marginBottom: 20, ...l1 }}
      />
      <div style={{ fontSize: 96, fontWeight: 700, letterSpacing: -3, lineHeight: 1.05, ...l1 }}>
        L’OCR propose.
      </div>
      <div
        style={{
          fontSize: 96,
          fontWeight: 700,
          letterSpacing: -3,
          lineHeight: 1.05,
          color: C.blue,
          ...l2,
        }}
      >
        Vous décidez.
      </div>
      <div style={{ fontSize: 40, color: C.text2, fontWeight: 500, marginTop: 24, ...l3 }}>
        Chaque décision reste tracée.
      </div>
    </AbsoluteFill>
  );
};

export const OcrDemo = () => {
  useInter();
  return (
    <Frame>
      <Sequence from={START.hook} durationInFrames={D.hook}>
        <SceneFade duration={D.hook}><Hook /></SceneFade>
      </Sequence>
      <Sequence from={START.upload} durationInFrames={D.upload}>
        <SceneFade duration={D.upload}><Upload /></SceneFade>
      </Sequence>
      <Sequence from={START.ocr} durationInFrames={D.ocr}>
        <SceneFade duration={D.ocr}><Ocr /></SceneFade>
      </Sequence>
      <Sequence from={START.findings} durationInFrames={D.findings}>
        <SceneFade duration={D.findings}><Findings /></SceneFade>
      </Sequence>
      <Sequence from={START.decision} durationInFrames={D.decision}>
        <SceneFade duration={D.decision}><Decision /></SceneFade>
      </Sequence>
      <Sequence from={START.outro} durationInFrames={D.outro}>
        <Outro />
      </Sequence>
    </Frame>
  );
};
