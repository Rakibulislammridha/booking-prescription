// Network calls of the console's tenant screens (CONVENTIONS §7.2). One endpoint: the create form's live slug
// availability check. Everything else on these screens is an Inertia form post returning a redirect.
import { http } from '@shared/http';
import { route } from '@shared/routes';
import type { SlugCheck } from '@panel/Components/Super/Tenants/types';

/** Is `{slug}.{central}` free? `ignore` is the public_id of the tenant being renamed, so its own slug is not "taken". */
export async function checkSlug(slug: string, ignore?: string, signal?: AbortSignal): Promise<SlugCheck> {
  const { data } = await http.get<SlugCheck>(route('super.tenants.slug-check'), { params: { slug, ignore }, signal });
  return data;
}
