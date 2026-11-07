<?php

use App\Domain\Privacy\Enums\RetentionAction;
use App\Domain\Privacy\Enums\RetentionCategory;
use App\Domain\Privacy\Models\RetentionPolicy;

/*
 * Durate di conservazione in RetentionPolicy (09, CLAUDE.md regola 17). Verifica di 6.11 (ADR-0016):
 * categoria H per i link di reimpostazione della password usati o scaduti, 30 giorni, cancellazione,
 * in attesa della validazione del professionista. La cancellazione la esegue il job di 7.2 (#63).
 */

it('ha la categoria H dei link di reimpostazione della password: 30 giorni, cancellazione, non ancora validata', function () {
    $policy = RetentionPolicy::query()->where('category', RetentionCategory::H_PASSWORD_RESET_LINKS)->sole();

    expect($policy->retention_days)->toBe(30)
        ->and($policy->action)->toBe(RetentionAction::DELETE)
        ->and($policy->legal_basis_note)->toContain('ADR-0016')
        ->and($policy->validated_by)->toBeNull()
        ->and($policy->validated_at)->toBeNull();
});

it('la durata si cambia in configurazione senza toccare il codice', function () {
    $policy = RetentionPolicy::query()->where('category', RetentionCategory::H_PASSWORD_RESET_LINKS)->sole();
    $policy->forceFill(['retention_days' => 45])->save();

    expect(RetentionPolicy::query()->where('category', RetentionCategory::H_PASSWORD_RESET_LINKS)->value('retention_days'))->toBe(45);
});
