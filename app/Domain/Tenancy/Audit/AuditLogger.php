<?php

namespace App\Domain\Tenancy\Audit;

use App\Domain\Tenancy\Enums\AuditActorType;
use App\Domain\Tenancy\Models\AuditLog;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Unico punto di scrittura dell'audit log (02, 08). Non registra mai valori di campi personali (09):
 * le chiamate passano solo nomi di campi, identificativi e valori non personali.
 *
 * Il tenant della riga è quello del contesto corrente; fuori da un contesto si può indicare il tenant
 * già verificato (es. tenant dell'utente al login). Senza tenant la riga è un evento di piattaforma.
 */
final class AuditLogger
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuthFactory $auth,
        private readonly ?Request $request = null,
    ) {}

    /**
     * @param  array<string, mixed>  $changes  solo valori non personali
     */
    public function record(
        string $action,
        ?string $subjectType = null,
        ?string $subjectId = null,
        array $changes = [],
        ?string $tenantId = null,
        ?AuditActor $actor = null,
    ): void {
        $actor ??= $this->currentActor();
        $current = $this->tenantContext->currentTenantId();

        $write = function (?string $tenant) use ($action, $subjectType, $subjectId, $changes, $actor): void {
            AuditLog::query()->create([
                'tenant_id' => $tenant,
                'actor_type' => $actor->type,
                'actor_id' => $actor->id,
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'changes' => $changes,
                'ip' => $this->request?->ip(),
                'user_agent' => $this->userAgent(),
                'occurred_at' => now(),
            ]);
        };

        if ($current !== null) {
            $write($current);

            return;
        }

        if ($tenantId !== null) {
            $this->tenantContext->run($tenantId, fn () => $write(strtolower($tenantId)));

            return;
        }

        $write(null);
    }

    /**
     * Registra la creazione o la modifica appena salvata di un modello (08 "Audit log"). Per i campi
     * #[PersonalData] resta solo il nome con il valore "[personal]" (02, 09); i campi nascosti del
     * modello (segreti, hash) diventano "[hidden]".
     *
     * @param  array<string, mixed>  $extra  valori aggiuntivi non personali
     */
    public function recordModelChange(string $action, Model $model, array $extra = [], ?AuditActor $actor = null): void
    {
        $key = $model->getKey();

        $this->record(
            $action,
            Str::snake(class_basename($model)),
            is_string($key) ? $key : null,
            AuditChanges::fromModel($model) + $extra,
            tenantId: is_string($model->getAttribute('tenant_id')) ? $model->getAttribute('tenant_id') : null,
            actor: $actor,
        );
    }

    private function currentActor(): AuditActor
    {
        $id = $this->auth->guard()->id();

        return is_string($id) ? new AuditActor(AuditActorType::USER, $id) : new AuditActor(AuditActorType::SYSTEM, null);
    }

    private function userAgent(): ?string
    {
        $agent = $this->request?->userAgent();

        return $agent === null ? null : mb_substr($agent, 0, 512);
    }
}
