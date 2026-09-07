// Vitest setup: DOM matchers + i18n initialised in English so components render real strings.
// The apps load one sliced locale bundle at runtime (shared/lang/*); tests get both files whole, so a component
// test never has to know which surface's prefixes it falls under.
import '@testing-library/jest-dom/vitest';
import { afterEach } from 'vitest';
import { cleanup } from '@testing-library/react';
import en from '@lang/en.json';
import bn from '@lang/bn.json';
import { addMessages, initI18n, type Messages } from '@shared/i18n';

addMessages('en', en as Messages);
addMessages('bn', bn as Messages);
initI18n('en');

// RTL only auto-registers cleanup when `afterEach` is a global (vitest globals are off).
afterEach(() => cleanup());
