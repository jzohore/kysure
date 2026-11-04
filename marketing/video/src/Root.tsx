import { Composition } from 'remotion';
import { OcrDemo, OCR_DEMO_DURATION } from './OcrDemo';

export const Root = () => (
  <Composition
    id="OcrDemo"
    component={OcrDemo}
    durationInFrames={OCR_DEMO_DURATION}
    fps={30}
    width={1080}
    height={1350}
  />
);
