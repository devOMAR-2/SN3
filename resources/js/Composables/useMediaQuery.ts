import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue';

/** Reactive `window.matchMedia(query).matches`, scoped to the calling component's lifetime. */
export function useMediaQuery(query: string): Readonly<Ref<boolean>> {
  const matches = ref(false);
  let media: MediaQueryList | undefined;

  const update = (event: MediaQueryListEvent | MediaQueryList): void => {
    matches.value = event.matches;
  };

  onMounted(() => {
    media = window.matchMedia(query);
    update(media);
    media.addEventListener('change', update);
  });

  onBeforeUnmount(() => media?.removeEventListener('change', update));

  return matches;
}
