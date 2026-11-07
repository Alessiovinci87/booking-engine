import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { type ChangeEvent, useRef } from 'react';
import { useTranslation } from 'react-i18next';

import { adminApi, type UnitPhoto } from '../../api/admin';
import { fieldErrors } from '../../api/client';
import { useErrorMessage } from '../../auth/session';
import { dangerButton, FormError, secondaryButton, Section } from '../../components/Form';

/**
 * Foto dell'unità (11, attività 2.2): caricamento (il server toglie EXIF e GPS), ordine e
 * rimozione. La prima foto è la copertina. Le immagini arrivano con URL firmati temporanei.
 */
export function UnitPhotosSection({ unitId }: { unitId: string }) {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const errorMessage = useErrorMessage();
    const input = useRef<HTMLInputElement>(null);
    const queryKey = ['unit-photos', unitId];
    const photos = useQuery({ queryKey, queryFn: () => adminApi.photos(unitId) });
    const setList = (data: { data: UnitPhoto[] }) => queryClient.setQueryData(queryKey, data);

    const upload = useMutation({
        mutationFn: (file: File) => adminApi.uploadPhoto(unitId, file),
        onSettled: () => queryClient.invalidateQueries({ queryKey }),
    });
    const reorder = useMutation({ mutationFn: (ids: string[]) => adminApi.reorderPhotos(unitId, ids), onSuccess: setList });
    const remove = useMutation({
        mutationFn: (photoId: string) => adminApi.deletePhoto(unitId, photoId),
        onSettled: () => queryClient.invalidateQueries({ queryKey }),
    });

    const list = photos.data?.data ?? [];
    const error = upload.error ?? reorder.error ?? remove.error ?? photos.error;
    const uploadErrors = fieldErrors(upload.error).photo;

    const move = (index: number, delta: number) => {
        const ids = list.map((photo) => photo.id);
        const target = index + delta;
        const [moved] = ids.splice(index, 1);

        if (moved !== undefined && target >= 0 && target <= ids.length) {
            ids.splice(target, 0, moved);
            reorder.mutate(ids);
        }
    };

    const onFile = (event: ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0];

        if (file !== undefined) {
            upload.mutate(file);
        }
        event.target.value = '';
    };

    return (
        <Section
            title={t('photos.title')}
            actions={
                <>
                    <input ref={input} className="hidden" type="file" accept="image/jpeg,image/png,image/webp" onChange={onFile} />
                    <button className={secondaryButton} type="button" onClick={() => input.current?.click()} disabled={upload.isPending}>
                        {upload.isPending ? t('photos.uploading') : t('photos.add')}
                    </button>
                </>
            }
        >
            <p className="mb-3 text-sm text-muted">{t('photos.hint')}</p>
            {uploadErrors !== undefined ? (
                <FormError message={uploadErrors.join(' ')} />
            ) : (
                error !== null && <FormError message={errorMessage(error)} />
            )}
            {photos.isSuccess && list.length === 0 && <p className="text-muted">{t('photos.empty')}</p>}
            <ul className="mt-3 grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4">
                {list.map((photo, index) => (
                    <li key={photo.id} className="rounded-control border border-line-soft p-2">
                        <img src={photo.urls.thumbnail} alt={t('photos.alt', { position: index + 1 })} className="aspect-[4/3] w-full rounded object-cover" width={320} height={240} loading="lazy" decoding="async" />
                        <div className="mt-2 flex items-center justify-between gap-1 text-xs">
                            <span className="text-muted">{photo.is_cover ? t('photos.cover') : `#${index + 1}`}</span>
                            <span className="flex gap-1">
                                <button className={secondaryButton} type="button" aria-label={t('photos.moveUp')} disabled={index === 0 || reorder.isPending} onClick={() => move(index, -1)}>
                                    ←
                                </button>
                                <button
                                    className={secondaryButton}
                                    type="button"
                                    aria-label={t('photos.moveDown')}
                                    disabled={index === list.length - 1 || reorder.isPending}
                                    onClick={() => move(index, 1)}
                                >
                                    →
                                </button>
                                <button
                                    className={dangerButton}
                                    type="button"
                                    aria-label={t('photos.remove')}
                                    disabled={remove.isPending}
                                    onClick={() => {
                                        if (window.confirm(t('photos.confirmRemove'))) {
                                            remove.mutate(photo.id);
                                        }
                                    }}
                                >
                                    ×
                                </button>
                            </span>
                        </div>
                    </li>
                ))}
            </ul>
        </Section>
    );
}
