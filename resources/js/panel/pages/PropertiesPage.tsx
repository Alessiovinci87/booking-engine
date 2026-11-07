import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { type FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router';

import { adminApi } from '../api/admin';
import { fieldErrors } from '../api/client';
import { useErrorMessage } from '../auth/session';
import { Field, FormError, primaryButton, Section, TextInput } from '../components/Form';

/** Home del pannello: strutture del tenant e creazione di una nuova struttura (01, AC1). */
export function PropertiesPage() {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const errorMessage = useErrorMessage();
    const properties = useQuery({ queryKey: ['properties'], queryFn: adminApi.properties });
    const [name, setName] = useState('');
    const [slug, setSlug] = useState('');

    const create = useMutation({
        mutationFn: () => adminApi.createProperty({ name, slug, translations: { it: { name, description: null, house_rules: null } } }),
        onSuccess: (property) => {
            void queryClient.invalidateQueries({ queryKey: ['properties'] });
            void navigate(`/strutture/${property.id}`);
        },
    });
    const errors = fieldErrors(create.error);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        create.mutate();
    };

    return (
        <main className="mx-auto max-w-5xl space-y-6 px-4 py-8">
            <h1 className="text-2xl font-semibold text-ink">{t('properties.title')}</h1>

            <Section title={t('properties.list')}>
                {properties.isPending && <p className="text-muted">{t('common.loading')}</p>}
                {properties.isError && <FormError message={errorMessage(properties.error)} />}
                {properties.data?.data.length === 0 && <p className="text-muted">{t('properties.empty')}</p>}
                <ul className="divide-y divide-line-soft">
                    {properties.data?.data.map((property) => (
                        <li key={property.id} className="flex items-center justify-between py-3">
                            <Link to={`/strutture/${property.id}`} className="font-medium text-brand-800 hover:underline">
                                {property.name}
                            </Link>
                            <span className="text-sm text-muted">{t(`properties.status.${property.status}`)}</span>
                        </li>
                    ))}
                </ul>
            </Section>

            <Section title={t('properties.create')}>
                <form className="grid gap-4 md:grid-cols-3 md:items-end" onSubmit={submit} noValidate>
                    <Field label={t('property.name')} errors={errors.name}>
                        <TextInput value={name} onChange={setName} required />
                    </Field>
                    <Field label={t('property.slug')} errors={errors.slug} hint={t('property.slugHint')}>
                        <TextInput value={slug} onChange={(value) => setSlug(value.toLowerCase())} required />
                    </Field>
                    <div>
                        <button className={primaryButton} type="submit" disabled={create.isPending}>
                            {create.isPending ? t('common.wait') : t('properties.createSubmit')}
                        </button>
                    </div>
                    <div className="md:col-span-3">
                        <FormError message={create.isError && Object.keys(errors).length === 0 ? errorMessage(create.error) : null} />
                    </div>
                </form>
            </Section>
        </main>
    );
}
