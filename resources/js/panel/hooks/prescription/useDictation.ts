// Voice dictation (PRESCRIPTION.md §4.10): window.SpeechRecognition ?? webkitSpeechRecognition, bn-BD | en-US,
// continuous with interim results. Audio never leaves the browser. When the API is absent the hook reports
// `supported: false` and the button is simply not rendered — nothing else changes.
import { useCallback, useEffect, useRef, useState } from 'react';

interface SpeechAlternative {
  transcript: string;
}
interface SpeechResult {
  isFinal: boolean;
  0: SpeechAlternative;
  length: number;
}
interface SpeechResultList {
  length: number;
  [index: number]: SpeechResult;
}
interface SpeechEvent {
  resultIndex: number;
  results: SpeechResultList;
}
interface SpeechRecognitionLike {
  lang: string;
  continuous: boolean;
  interimResults: boolean;
  start(): void;
  stop(): void;
  abort(): void;
  onresult: ((event: SpeechEvent) => void) | null;
  onerror: ((event: { error?: string }) => void) | null;
  onend: (() => void) | null;
}
type SpeechConstructor = new () => SpeechRecognitionLike;

function constructor(): SpeechConstructor | null {
  const w = window as unknown as { SpeechRecognition?: SpeechConstructor; webkitSpeechRecognition?: SpeechConstructor };
  return w.SpeechRecognition ?? w.webkitSpeechRecognition ?? null;
}

export interface Dictation {
  supported: boolean;
  listening: boolean;
  interim: string;
  lang: string;
  setLang(lang: string): void;
  toggle(): void;
  stop(): void;
}

export function useDictation(onText: (text: string) => void, initialLang = 'bn-BD'): Dictation {
  const [supported] = useState(() => constructor() !== null);
  const [listening, setListening] = useState(false);
  const [interim, setInterim] = useState('');
  const [lang, setLang] = useState(initialLang);
  const recognition = useRef<SpeechRecognitionLike | null>(null);
  const sink = useRef(onText);
  sink.current = onText;

  const stop = useCallback(() => {
    recognition.current?.stop();
    recognition.current = null;
    setListening(false);
    setInterim('');
  }, []);

  const toggle = useCallback(() => {
    if (listening) {
      stop();
      return;
    }
    const Ctor = constructor();
    if (Ctor === null) return;
    const engine = new Ctor();
    engine.lang = lang;
    engine.continuous = true;
    engine.interimResults = true;
    engine.onresult = (event) => {
      let pending = '';
      for (let i = event.resultIndex; i < event.results.length; i++) {
        const result = event.results[i];
        if (result === undefined) continue;
        const text = result[0].transcript;
        if (result.isFinal) sink.current(text.trim());
        else pending += text;
      }
      setInterim(pending);
    };
    engine.onerror = () => stop();
    engine.onend = () => setListening(false);
    recognition.current = engine;
    engine.start();
    setListening(true);
  }, [lang, listening, stop]);

  useEffect(() => () => recognition.current?.abort(), []);

  return { supported, listening, interim, lang, setLang, toggle, stop };
}
