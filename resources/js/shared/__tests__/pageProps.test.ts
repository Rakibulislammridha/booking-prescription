// PageProps<T> must let a page's OWN props win over the shared ones. `SharedProps & T` intersected a colliding
// key (a page prop named `branches` became `BranchOption[] & MyRow[]`), which is neither what the server sends
// nor what the page reads — pages had to rename their prop to work around it. These are compile-time assertions:
// the file fails `npm run typecheck` if the shadowing ever comes back.
import { describe, expect, it } from 'vitest';
import type { PageProps } from '@shared/types/inertia';

interface Row {
  id: number;
  label: string;
}

type Shadowed = PageProps<{ branches: Row[]; rows: Row[] }>;
type Plain = PageProps<{ rows: Row[] }>;

/** Compiles only when X and Y are the same type. */
type Exact<X, Y> = (<T>() => T extends X ? 1 : 2) extends <T>() => T extends Y ? 1 : 2 ? true : false;

const pageWins: Exact<Shadowed['branches'], Row[]> = true;
const otherSharedPropsSurvive: Exact<Plain['locale'], 'bn' | 'en'> = true;
const pageOwnPropsSurvive: Exact<Plain['rows'], Row[]> = true;

describe('PageProps', () => {
  it('gives a page prop its own type even when a shared prop has the same name', () => {
    expect([pageWins, otherSharedPropsSurvive, pageOwnPropsSurvive]).toEqual([true, true, true]);
  });
});
