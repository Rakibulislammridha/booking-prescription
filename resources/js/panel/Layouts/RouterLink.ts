// Inertia's <Link> typed as a plain anchor so MUI's `component` prop accepts it (Link, ListItemButton, Button…).
// Inertia Link renders an <a> and handles href/onClick itself; nothing is lost by presenting anchor props.
import type { AnchorHTMLAttributes, ForwardRefExoticComponent, RefAttributes } from 'react';
import { Link } from '@inertiajs/react';

export type RouterLinkProps = AnchorHTMLAttributes<HTMLAnchorElement> & RefAttributes<HTMLAnchorElement>;

export const RouterLink = Link as unknown as ForwardRefExoticComponent<RouterLinkProps>;
