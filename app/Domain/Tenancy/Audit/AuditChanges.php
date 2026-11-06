<?php

namespace App\Domain\Tenancy\Audit;

use App\Domain\Privacy\PersonalDataRegistry;
use Illuminate\Database\Eloquent\Model;

/**
 * Costruisce il campo changes dell'audit da un modello appena salvato (02 "AuditLog"):
 * { campo: { from, to } } per i campi ordinari, { campo: "[personal]" } per i campi #[PersonalData]
 * e { campo: "[hidden]" } per i campi nascosti del modello. Nessun valore personale esce da qui.
 */
final class AuditChanges
{
    public const PERSONAL = '[personal]';

    public const HIDDEN = '[hidden]';

    /** Colonne tecniche che non descrivono la modifica. */
    private const IGNORED = ['id', 'tenant_id', 'created_at', 'updated_at'];

    /**
     * @return array<string, mixed>
     */
    public static function fromModel(Model $model): array
    {
        // wasRecentlyCreated resta vero anche dopo i salvataggi successivi: è una creazione solo se
        // l'ultimo salvataggio non ha registrato modifiche (l'insert non le registra).
        $created = $model->wasRecentlyCreated && $model->getChanges() === [];
        $after = $created ? $model->getAttributes() : $model->getChanges();
        $before = $created ? [] : $model->getPrevious();
        $hidden = $model->getHidden();
        $changes = [];

        foreach ($after as $field => $value) {
            if (in_array($field, self::IGNORED, true)) {
                continue;
            }

            $changes[$field] = match (true) {
                PersonalDataRegistry::isPersonal($model, $field) => self::PERSONAL,
                in_array($field, $hidden, true) => self::HIDDEN,
                default => ['from' => $before[$field] ?? null, 'to' => $value],
            };
        }

        ksort($changes);

        return $changes;
    }
}
