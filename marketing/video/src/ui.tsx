import { useEffect, useState } from 'react';
import {
  AbsoluteFill,
  continueRender,
  delayRender,
  Easing,
  interpolate,
  spring,
  staticFile,
  useCurrentFrame,
  useVideoConfig,
} from 'remotion';

export const C = {
  sable: '#fcfaf8',
  ink: '#0f172a',
  text2: '#475569',
  border: '#e2e8f0',
  blue: '#2563eb',
  emerald: '#10b981',
  amber: '#f59e0b',
  slate50: '#f8fafc',
};

export const FONT = "'Inter', ui-sans-serif, -apple-system, sans-serif";

/** Charge Inter (variable) avant le rendu de chaque image. */
export const useInter = () => {
  const [handle] = useState(() => delayRender('inter'));
  useEffect(() => {
    const face = new FontFace('Inter', `url(${staticFile('InterVariable.woff2')})`, {
      weight: '100 900',
    });
    face.load().then((f) => {
      document.fonts.add(f);
      continueRender(handle);
    });
  }, [handle]);
};

/** Opacité + léger glissement vertical à l'apparition. */
export const useReveal = (delay = 0, distance = 24) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const s = spring({ frame: frame - delay, fps, config: { damping: 200 } });
  return { opacity: s, transform: `translateY(${(1 - s) * distance}px)` };
};

export const ease = Easing.bezier(0.16, 1, 0.3, 1);

export const Card = ({
  children,
  style,
}: {
  children: React.ReactNode;
  style?: React.CSSProperties;
}) => (
  <div
    style={{
      background: '#fff',
      border: `2px solid ${C.border}`,
      borderRadius: 28,
      boxShadow: '0 12px 40px rgba(15,23,42,0.07)',
      ...style,
    }}
  >
    {children}
  </div>
);

/** Sous-titre incrusté (lecture sans le son). */
export const Caption = ({ text, duration }: { text: string; duration: number }) => {
  const frame = useCurrentFrame();
  const opacity = interpolate(frame, [4, 14, duration - 12, duration - 2], [0, 1, 1, 0], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });
  return (
    <div
      style={{
        position: 'absolute',
        left: 60,
        right: 60,
        bottom: 96,
        display: 'flex',
        justifyContent: 'center',
        opacity,
      }}
    >
      <div
        style={{
          background: 'rgba(15,23,42,0.92)',
          color: '#fff',
          fontSize: 44,
          fontWeight: 600,
          lineHeight: 1.25,
          textAlign: 'center',
          padding: '20px 32px',
          borderRadius: 20,
        }}
      >
        {text}
      </div>
    </div>
  );
};

/** Fond + bandeau marque commun à toutes les scènes. */
export const Frame = ({ children }: { children: React.ReactNode }) => (
  <AbsoluteFill style={{ background: C.sable, fontFamily: FONT, color: C.ink }}>
    <div
      style={{
        position: 'absolute',
        top: 48,
        left: 60,
        right: 60,
        display: 'flex',
        justifyContent: 'space-between',
        alignItems: 'center',
      }}
    >
      <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
        <img
          src={staticFile('kysure_logo.png')}
          style={{ width: 84, height: 46, objectFit: 'contain' }}
        />
        <span style={{ fontSize: 34, fontWeight: 700, letterSpacing: -0.5 }}>Kysure</span>
      </div>
      <span
        style={{
          fontSize: 22,
          fontWeight: 500,
          color: C.text2,
          border: `2px solid ${C.border}`,
          borderRadius: 999,
          padding: '6px 16px',
          background: '#fff',
        }}
      >
        Données de démonstration
      </span>
    </div>
    {children}
  </AbsoluteFill>
);

export const Check = ({ color = C.emerald, size = 36 }: { color?: string; size?: number }) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill="none" aria-hidden="true">
    <circle cx="12" cy="12" r="11" fill={color} />
    <path
      d="M7 12.5l3.2 3.2L17 9"
      stroke="#fff"
      strokeWidth="2.6"
      strokeLinecap="round"
      strokeLinejoin="round"
    />
  </svg>
);

export const Warn = ({ size = 36 }: { size?: number }) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill="none" aria-hidden="true">
    <path d="M12 2.5l10 18H2L12 2.5z" fill={C.amber} />
    <path d="M12 9v5" stroke="#fff" strokeWidth="2.4" strokeLinecap="round" />
    <circle cx="12" cy="17" r="1.3" fill="#fff" />
  </svg>
);
